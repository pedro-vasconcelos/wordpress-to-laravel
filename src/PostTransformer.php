<?php
/**
 * Created by PhpStorm.
 * User: leeovery
 * Date: 19/11/2016
 * Time: 10:57.
 */

namespace LeeOvery\WordpressToLaravel;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\Fractal\Resource\Item;
use League\Fractal\TransformerAbstract;

class PostTransformer extends TransformerAbstract
{
    protected $defaultIncludes = [
        'author',
        'category',
        'tags',
    ];

    /**
     * @var
     */
    private $authorTransformer;

    /**
     * @var
     */
    private $categoryTransformer;

    /**
     * @var
     */
    private $tagTransformer;

    /**
     * PostTransformer constructor.
     *
     * @param $authorTransformer
     * @param $categoryTransformer
     * @param $tagTransformer
     */
    public function __construct($authorTransformer, $categoryTransformer, $tagTransformer)
    {
        $this->authorTransformer = $authorTransformer;
        $this->categoryTransformer = $categoryTransformer;
        $this->tagTransformer = $tagTransformer;
    }

    public function transform($post)
    {
        return [
            'wp_id' => (int) $post->id,
            'type' => $post->type,
            'title' => $post->title->rendered,
            'slug' => $post->slug,
            'link' => $post->link,
            'sticky' => $post->sticky ?? 0,
            'excerpt' => $post->excerpt->rendered ?? '',
            'content' => $post->content->rendered ?? '',
            'format' => $post->format ?? null,
            'status' => $post->status,
            'featured_image' => $this->getFeaturedImage($post),
            'published_at' => $this->carbonDate($post->date),
            'created_at' => $this->carbonDate($post->date),
            'updated_at' => $this->carbonDate($post->modified),
        ];
    }

    /**
     * Include author.
     *
     * @param $post
     *
     * @return Item
     */
    public function includeAuthor($post)
    {
        return $this->item($post, new $this->authorTransformer());
    }

    /**
     * Include category.
     *
     * @param $post
     *
     * @return Item
     */
    public function includeCategory($post)
    {
        return $this->item($post, new $this->categoryTransformer());
    }

    /**
     * Include tags.
     *
     * @param $post
     *
     * @return Item
     */
    public function includeTags($post)
    {
        return $this->item($post, new $this->tagTransformer());
    }

    private function getFeaturedImage($post)
    {
        $embedded = collect($post->_embedded ?? []);

        if ($embedded->has('wp:featuredmedia')) {
            $media = head($embedded['wp:featuredmedia']);

            if (isset($media->source_url)) {
                $url_array = parse_url($media->source_url);
                $url_path = $url_array['path'];
                $url_path_array = explode('/', $url_path);
                $filename = strtolower(last($url_path_array));

                if (Storage::disk('blog')->missing($filename)) {
                    try {
                        $contents = file_get_contents($media->source_url);
                        Storage::disk('blog')->put(
                            $filename,
                            $contents,
                        );
                        Log::info('Downloaded image: '.$media->source_url);

                    } catch (Exception $exception) {
                        Log::alert($exception->getMessage());
                    }
                } else {
                    Log::info('Image exists: '.$media->source_url);
                }

                return $filename;
            }
        }

        return null;
    }

    /**
     * @param $date
     *
     * @return Carbon
     */
    private function carbonDate($date)
    {
        return Carbon::parse($date);
    }
}
