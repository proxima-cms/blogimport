<?php defined('SYSPATH') or die('No direct script access.');

class Importer_Driver_Tumblr extends Importer_Driver
{
	/**  
	 * Load posts from the blog XML feed and try to save them to the database.
	 *
	 * @return	array
	 */
	public function import_posts()
	{
		try
		{
			$data = simplexml_load_file($this->config['feeds']['posts'], 'SimpleXMLElement', LIBXML_NOCDATA);
		}
		catch(ErrorException $e)
		{
			throw $e;
		}

		$data = (array) $data->posts;
		$posts = $data['post'];

		return array(
			'saved' => $this->save_posts($posts),
			'total_posts' => count($posts)
		);
	}

	/**  
	 * Try save posts to the database.
	 * (Tumblr Posts types: text, quote, photo, link, chat, video, audio)
	 *
	 * @param		array		$posts	Array of posts to save to the database
	 * @return	integer
	 */
	public function save_posts($posts = array())
	{
		$saved = 0;

		foreach($posts as $data)
		{
			$data = (array) $data;

			// Quote post
			if (isset($data['quote-text']))
			{
				$title = $data['quote-text'];
				$intro = $body = $data['quote-text'].' - '.$data['quote-source'];
			}
			// Text post with title
			else if (isset($data['regular-title']) AND isset($data['regular-body']))
			{
				$title = $data['regular-title'];
				$intro = $body = $data['regular-body'];
			}
			// Text post with no body
			else if (isset($data['regular-title']))
			{
				$title = $intro = $body = $data['regular-title'];
			}
			// Text post with no title
			else if (isset($data['regular-body']))
			{
				$title = $intro = $body = $data['regular-body'];
			}
			// Link post with text
			else if (isset($data['link-url']) AND isset($data['link-text']))
			{
				$title = $data['link-text'];
				$intro = $body = HTML::anchor($data['link-url'], $data['link-url']).' '.@$data['link-description'];
			}
			// Link post with no text
			else if (isset($data['link-url']))
			{
				$title = $data['link-url'];
				$intro = $body = HTML::anchor($data['link-url'], $data['link-url']);
			}
			// Chat post
			else if (isset($data['conversation-title']))
			{
				$title = $data['conversation-title'];
				$intro = $body = $data['conversation-text'];
			}
			// Question post
			else if (isset($data['question']) AND isset($data['answer']))
			{
				$title = 'Question: '.$data['question'];
				$intro = $body = $data['answer'];
			}
			// Audio post
			else if (isset($data['audio-caption']) AND isset($data['audio-player']))
			{
				$title = $data['audio-caption'];
				$intro = $body = $title.' '.$data['audio-player'];
			}
			// Photo post with title
			else if (isset($data['photo-caption']) AND isset($data['photo-url']))
			{
				// Photo path must have an extension for this to work.
				$photo_path = $data['photo-url'][1];

				// Download the asset and save it to the database
				$asset = Asset::add($photo_path);

				$title = $data['photo-caption'];

				$intro = $body = $title . HTML::image($asset->path());
			}
			// Photo post with no title
			else if (isset($data['photo-url']))
			{
				$title = 'Photo on '.$data['@attributes']['unix-timestamp'];
				$intro = $body = HTML::image($data['photo-url'][0]);
			}
			// Video embed post
			else if (isset($data['video-caption']) AND isset($data['video-player']))
			{
				$title = $data['video-caption'];
				$intro = $body = $data['video-player'];
			}
			// Unsupported post
			else
			{
				continue;
			}

			// Format the post title
			$title = substr(strip_tags($title), 0, 100);

			// Does the post already exist?
			$page = ORM::factory('page')->where('title', '=', $title)->find();

			if ($page->loaded())
			{
				continue;
			}

			// Set post data
			$page->title = $title;
			$page->description = $title;
			$page->parent_id = $this->config['parent_id'];
			$page->body = (string) $body;
			$page->draft = (string) $this->config['status'];
			$page->pagetype_id = $this->config['page_type_id'];
			$page->user_id = Auth::instance()->get_user()->id;
			$page->visible_from = date('Y-m-d H:i:s', $data['@attributes']['unix-timestamp']);
			$page->generate_uri($this->config['url_prefix']);
		
			// Save page
			$saved += (int) !!$page->save();

			// Save page tags
			$tags = (array) @$data['tag'];

			if ($tags && $this->config['categories'])
			{
				$this->save_page_tags($page, $tags);
			}
			
			// Save page redirect.
			$redirect_url = parse_url($data['@attributes']['url-with-slug']);
			$redirect_uri = ltrim($redirect_url['path'], '/');

			$redirect = ORM::factory('redirect')->where('uri', '=', $redirect_uri)->find();
			$redirect->uri = $redirect_uri;
			$redirect->target = 'page';
			$redirect->target_id = $page->id;
			$redirect->save();
		}

		return $saved;
	}

	/**  
	 * Create new tags, and create tag => page relationships
	 *
	 * @param		mixed		$page	Page ORM object
	 * @param		array		$tags	Array of tags to save
	 * @return	void
	 */
	public function save_page_tags($page = NULL, $tags = array())
	{
		foreach($tags as $tag)
		{
			$tag_model = ORM::factory('tag')->where('name', '=', $tag)->find();

			if (!$tag_model->loaded())
			{
				$tag_model->name = $tag;
				$tag_model->slug = $tag;
				$tag_model->save();
			}
			
			$page->add('tags', new Model_Tag(array('id' => $tag_model->id)));
		}
	}
}
