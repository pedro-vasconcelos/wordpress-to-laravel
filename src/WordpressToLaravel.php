<?php

namespace LeeOvery\WordpressToLaravel;

use DB;
use Exception;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Fractal\Manager as FractalManager;
use League\Fractal\Resource\Item;
use League\Fractal\TransformerAbstract;
use stdClass;

class WordpressToLaravel
{
    /**
     * @var string
     */
    protected $endpoint = 'wp-json/wp/v2/';

    /**
     * @var FractalManager
     */
    protected $fractalManager;

    /**
     * @var GuzzleClient
     */
    protected $client;

    /**
     * @var array
     */
    protected $config;

    /**
     * @var string
     */
    protected $postModel;

    /**
     * @var string
     */
    protected $categoryModel;

    /**
     * @var string
     */
    protected $authorModel;

    /**
     * @var TransformerAbstract
     */
    protected $postTransformer;

    /**
     * @var TransformerAbstract
     */
    protected $categoryTransformer;

    /**
     * @var TransformerAbstract
     */
    protected $authorTransformer;

    /**
     * @var TransformerAbstract
     */
    protected $tagTransformer;

    /**
     * WordpressToLaravel constructor.
     */
    public function __construct(FractalManager $fractalManager, GuzzleClient $client, array $config)
    {
        $this->fractalManager = $fractalManager;
        $this->client = $client;
        $this->config = $config;

        $this->setupModels();
        $this->setupTransformers();
    }

    /**
     * @param string $postRestBase
     * @param int    $page
     * @param int    $perPage
     * @param bool   $truncate
     * @param bool   $forceAll
     */
    public function import($postRestBase, $page = 1, $perPage = 5, $truncate = false, $forceAll = false)
    {
        $this->truncate($truncate)
            ->fetchPosts($postRestBase, $page, $perPage, $forceAll)
            ->map(function ($post) {
                return $this->transformPost($post);
            })
            ->each(function ($post) {
                $this->syncPost($post);
            })
        ;
    }

    protected function setupModels()
    {
        $this->postModel = $this->config['post_model'] ?? Post::class;
        $this->categoryModel = $this->config['category_model'] ?? Category::class;
        $this->authorModel = $this->config['author_model'] ?? Author::class;
    }

    protected function setupTransformers()
    {
        $this->postTransformer = Arr::get($this->config, 'transformers.post') ?? PostTransformer::class;
        $this->categoryTransformer = Arr::get($this->config, 'transformers.category') ?? CategoryTransformer::class;
        $this->authorTransformer = Arr::get($this->config, 'transformers.author') ?? AuthorTransformer::class;
        $this->tagTransformer = Arr::get($this->config, 'transformers.tag') ?? TagTransformer::class;
    }

    /**
     * Setup the getPosts request.
     *
     * @param string $postRestBase
     * @param int    $page
     * @param int    $perPage
     * @param bool   $forceAll
     *
     * @return Collection
     */
    protected function fetchPosts($postRestBase, $page, $perPage, $forceAll)
    {
        $posts = collect();

        while (true) {
            $stop = collect(
                $this->sendRequest($this->makeUrl($postRestBase, $page++, $perPage))
            )->map(function ($post) use ($posts) {
                $posts->push($post);
            })->isEmpty();

            if (!$forceAll || $stop) {
                break;
            }
        }
//        collect(
//            $this->sendRequest($this->makeUrl($postRestBase, 1, 10))
//        )->map(function ($post) use ($posts) {
//            $posts->push($post);
//        });

        return $posts;
    }

    /**
     * Send the request.
     *
     * @param string $url
     * @param int    $tries
     *
     * @return array
     */
    protected function sendRequest($url, $tries = 3)
    {
        --$tries;

        beginning:
        try {
            $results = $this->client->get($url);
        } catch (ConnectException $e) {
            if (!$tries) {
                return [];
            }

            --$tries;

            usleep(100);

            goto beginning;
        } catch (Exception $e) {
            return [];
        }

        if ($results) {
            return json_decode(
                $results->getBody()
            );
        }

        return [];
    }

    /**
     * @param string $postRestBase
     * @param int    $page
     * @param int    $perPage
     *
     * @return string
     */
    protected function makeUrl($postRestBase, $page, $perPage)
    {
        // %s?_embed=true&orderby=modified&page=%d&per_page=%d&after=2020-01-01T00:00:01.552Z
        // %s?_embed=true&filter[orderby]=modified&page=%d&per_page=%d
        $queryString = sprintf(
            '%s?_embed=true&order=desc&orderby=modified&page=%d&per_page=%d&after=2021-01-01T00:00:01.552Z',
            $postRestBase,
            $page,
            $perPage
        );

        return sprintf(
            '%s%s%s',
            Str::finish($this->config['api_url'], '/'),
            $this->endpoint,
            $queryString
        );
    }

    protected function truncate($truncate)
    {
        if ($truncate) {
            ($this->postModel)::truncate();
            ($this->categoryModel)::truncate();
            ($this->authorModel)::truncate();
            DB::table('tags')->truncate();
            DB::table('tagged')->truncate();
        }

        return $this;
    }

    /**
     * @return array
     */
    protected function transformPost(stdClass $post)
    {
        return $this->fractalManager->createData($this->createPostResource($post))
            ->toArray()
        ;
    }

    /**
     * @param array $data
     */
    protected function syncPost($data)
    {
        $tagsData = $data['tags'];
        $authorData = $data['author'];
        $categoryData = $data['category'];
        unset($data['tags'], $data['author'], $data['category']);

        $this->getPostImagesToStorage($data['content']);

        $data['content'] = $this->parseLinks($data['content']);

        if (!$post = ($this->postModel)::where('wp_id', $data['wp_id'])->first()) {
            $post = ($this->postModel)::create($data);
        }

        if ($data['updated_at']->gt($post->updated_at)) {
            $post->update($data);
            event(new PostUpdated($post));
        }

        $post->setTags($tagsData);
        $post->setCategory($categoryData);
        $post->setAuthor($authorData);
        $post->save();

        if ($post->wasRecentlyCreated) {
            event(new PostImported($post));
        }
    }

    private function createPostResource(stdClass $post): Item
    {
        return new Item($post, new $this->postTransformer(
            $this->authorTransformer,
            $this->categoryTransformer,
            $this->tagTransformer
        ));
    }

    private function parseLinks($content)
    {
        // todo ver o caso do embed do video no post: https://navigator-paper.com.test/en/blog/article/impact-paper-daily-life-lookingforyou-campaign
        // check this: https://github.com/zoonru/commonmark-ext-youtube-iframe

        // Regex
        $find = [
            '/http(s?):\/\/navigator-business-optimizer.com\/\d{4}\/\d{2}\/([^"|^"]*)\//i',
            '/\(\/\d{4}\/\d{2}\/([^"|^"]*)\//Ui',
            '/http(s?):\/\/navigator-business-optimizer.com\/wp-content\/uploads\/\d{4}\/\d{2}\//i',
            '/images\/(.*?)\)/i',
            '/.\[(\/?)vc(.*?)\\]/i',
            '/.\[blockquote(.*?)\\\]/i',
            '/\ class="wp-block[^"]*\"/i',
            '/\ class="wp-image[^"]*\"/i',
            '/\ id="block-[^"]*\"/i',
        ];

        $replace = [
            '/en/blog/article/${2}',
            '/en/blog/article/${1}',
            '/blog/',
            '/blog/${1})',
            ' ',
            '> ',
            '',
            '',
            '',
        ];

        $content = preg_replace($find, $replace, $content);

        $find = [
            '\[/blockquote\]',
            '\[dropcap background="" color="" circle="0" size="1"\]',
            '\[/dropcap\]',
            '[/idea]',
            '\[/idea\]',
            '\[idea\]',
            'xE2x80x8B',
        ];
        $replace = [
            '',
            '',
            '',
            '',
            '',
            '',
            '',
        ];

        return str_replace($find, $replace, $content);
    }

    private function getPostImagesToStorage($content): void
    {
        $images = [];

        preg_match_all(
            '/http[s?]:\/\/navigator-business-optimizer.com\/wp-content\/uploads\/\d{4}\/\d{2}\/[^"|^"|^ ]*/i',
            $content,
            $images,
            PREG_OFFSET_CAPTURE
        );

        if (count($images[0]) > 0) {
            foreach ($images[0] as $image) {
                $url_array = parse_url($image[0]);
                $url_path = $url_array['path'];
                $url_path_array = explode('/', $url_path);
                $filename = strtolower(last($url_path_array));

                if (Storage::disk('blog')->missing($filename)) {
                    try {
                        $contents = file_get_contents($image[0]);
                        Storage::disk('blog')->put(
                            $filename,
                            $contents,
                        );
                        Log::info('Downloaded image: '.$image[0]);
                    } catch (Exception $exception) {
                        Log::alert($exception->getMessage());
                    }
                } else {
                    Log::info('Image exists: '.$image[0]);
                }
            }
        }
    }
}
