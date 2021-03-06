<?php
/**
*
* @package Gallery - Feed Extension
* @copyright (c) 2012 nickvergessen - http://www.flying-bits.org/
* @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
*
*/

/**
* @ignore
*/

if (!defined('IN_PHPBB'))
{
	exit;
}

if (!defined('GALLERY_IMAGES_TABLE'))
{
	define('GALLERY_IMAGES_TABLE', 'phpbb_gallery_images');
}

if (!defined('GALLERY_ALBUMS_TABLE'))
{
	define('GALLERY_ALBUMS_TABLE', 'phpbb_gallery_albums');
}

if (!defined('GALLERY_WATCH_TABLE'))
{
	define('GALLERY_WATCH_TABLE', 'phpbb_gallery_watch');
}

class phpbb_ext_gallery_feed
{
	/**
	* Separator for title elements to separate items (for example album / image_name)
	*/
	private $separator = "\xE2\x80\xA2"; // &bull;

	/**
	* Separator for the statistics row (Uploaded by, time, comments, etc.)
	*/
	private $separator_stats = "\xE2\x80\x94"; // &mdash;

	private $last_modified = false;
	private $sql_where = '';
	private $images_data = array();

	public function __construct($album_id)
	{
		if ($album_id)
		{
			$this->init_album_feed($album_id);
		}
		else
		{
			$this->init_gallery_feed();
		}
	}

	public function get_last_modified()
	{
		return $this->last_modified;
	}

	private function init_album_feed($album_id)
	{
		global $phpbb_ext_gallery;

		$album_data = phpbb_ext_gallery_core_album::get_info($album_id);
		$feed_enabled = true;//@todo: (!empty($album_data['album_feed']) && (($album_data['album_user_id'] == 0) || $phpbb_ext_gallery->config->get('feed_enable_pegas')));

		if ($feed_enabled)//@todo: && $phpbb_ext_gallery->auth->acl_check('i_view', $album_id, $album_data['album_user_id']))
		{
			$this->sql_where = 'image_album_id = ' . (int) $album_id;
			$this->get_images($album_data);
		}
		else
		{
			trigger_error('NO_FEED');
		}
	}

	private function init_gallery_feed()
	{
		global $db, $phpbb_ext_gallery;

		$sql = 'SELECT album_id
			FROM ' . GALLERY_ALBUMS_TABLE . '
			WHERE album_feed = 1';
		$result = $db->sql_query($sql);
		$feed_albums = array();
		while ($row = $db->sql_fetchrow($result))
		{
			$feed_albums[] = (int) $row['album_id'];
		}
		$db->sql_freeresult($result);

		if (empty($feed_albums))
		{
			trigger_error('NO_FEED');
		}


		$moderator_albums = $phpbb_ext_gallery->auth->acl_album_ids('m_status', 'array', true, true);//@todo: $phpbb_ext_gallery->config->get('feed_enable_pegas'));
		if (!empty($moderator_albums))
		{
			$moderator_albums = array_intersect($moderator_albums, $feed_albums);
		}
		$authorized_albums = array_diff($phpbb_ext_gallery->auth->acl_album_ids('i_view', 'array', true, true/*@todo: $phpbb_ext_gallery->config->get('feed_enable_pegas')*/), $moderator_albums);
		if (!empty($authorized_albums))
		{
			$authorized_albums = array_intersect($authorized_albums, $feed_albums);
		}

		if (empty($moderator_albums) && empty($authorized_albums))
		{
			trigger_error('NO_FEED');
		}

		$this->sql_where = '(' . ((!empty($authorized_albums)) ? '(' . $db->sql_in_set('image_album_id', $authorized_albums) . ' AND image_status <> ' . phpbb_ext_gallery_core_image::STATUS_UNAPPROVED . ')' : '');
		$this->sql_where .= ((!empty($moderator_albums)) ? ((!empty($authorized_albums)) ? ' OR ' : '') . '(' . $db->sql_in_set('image_album_id', $moderator_albums, false, true) . ')' : '') . ')';

		$this->get_images();
	}

	public function get_images($album_data = false)
	{
		global $db, $phpbb_ext_gallery;

		$sql_array = array(
			'SELECT'		=> 'i.*',
			'FROM'			=> array(GALLERY_IMAGES_TABLE => 'i'),

			'WHERE'			=> $this->sql_where . ' AND i.image_status <> ' . phpbb_ext_gallery_core_image::STATUS_ORPHAN,
			'ORDER_BY'		=> 'i.image_time DESC',
		);

		if ($album_data == false)
		{
			$sql_array['SELECT'] .= ', a.album_name, a.album_status, a.album_id, a.album_user_id';
			$sql_array['LEFT_JOIN'] = array(
				array(
					'FROM'		=> array(GALLERY_ALBUMS_TABLE => 'a'),
					'ON'		=> 'i.image_album_id = a.album_id',
				),
			);
		}
		$sql = $db->sql_build_query('SELECT', $sql_array);
		$result = $db->sql_query_limit($sql, 5);//@todo: $phpbb_ext_gallery->config->get('feed_limit'));

		while ($row = $db->sql_fetchrow($result))
		{
			if ($this->last_modified === false)
			{
				$this->last_modified = (int) $row['image_time'];
			}
			if ($album_data == false)
			{
				$this->images_data[$row['image_id']] = $row;
			}
			else
			{
				$this->images_data[$row['image_id']] = array_merge($row, $album_data);
			}
		}
		$db->sql_freeresult($result);
	}

	public function send_images()
	{
		global $user, $phpbb_ext_gallery, $template;

		foreach ($this->images_data as $image_id => $row)
		{
			$url_thumbnail = generate_board_url() . '/' . append_sid('gallery/image.php', 'mode=thumbnail&amp;album_id=' . $row['image_album_id'] . '&amp;image_id=' . $image_id, true, '');
			$url_imagepage = generate_board_url() . '/' . append_sid('gallery/image_page.php', 'album_id=' . $row['image_album_id'] . '&amp;image_id=' . $image_id, true, '');
			$url_fullsize = generate_board_url() . '/' . append_sid('gallery/image.php', 'album_id=' . $row['image_album_id'] . '&amp;image_id=' . $image_id, true, '');
			$title = censor_text($row['album_name'] . ' ' . $this->separator . ' ' . $row['image_name']);

			$description = $row['image_desc'];
			if ($row['image_desc_uid'])
			{
				// make list items visible as such
				$description = str_replace('[*:' . $row['image_desc_uid'] . ']', '*&nbsp;', $description);
				$description = str_replace('[/*:' . $row['image_desc_uid'] . ']', "\n", $description);
				// no BBCode
				strip_bbcode($description, $row['image_desc_uid']);
			}

			if ($row['image_contest'] == phpbb_ext_gallery_core_image::IN_CONTEST && !$phpbb_ext_gallery->auth->acl_check('m_status', $row['image_album_id'], phpbb_ext_gallery_core_album::PUBLIC_ALBUM))
			{
				$image_username = $user->lang['CONTEST_USERNAME'];
			}
			else if ($row['image_user_id'] == ANONYMOUS)
			{
				$image_username = $row['image_username'];
			}
			else
			{
				$url_profile = generate_board_url() . '/' . append_sid('memberlist.php', 'mode=viewprofile&amp;u=' . $row['image_user_id'], true, '');
				$image_username = '<a href="' . $url_profile . '">' . $row['image_username'] . '</a>';
			}

			$template->assign_block_vars('item_row', array(
				'TITLE'			=> $title,
				'IMAGE_TIME'	=> self::format_date($row['image_time']),
				'DESCRIPTION'	=> $description,
				'STATISTIC'		=> $user->lang['STATISTICS'] . ': ' . $image_username . ' ' . $this->separator_stats . ' ' . $user->format_date($row['image_time']),
				'MIME_TYPE'		=> phpbb_ext_gallery_core_file::mimetype_by_filename($row['image_filename']),

				'U_VIEWIMAGE'	=> $url_imagepage,
				'U_FULL_IMAGE'	=> $url_fullsize,
				'U_THUMBNAIL'	=> $url_thumbnail,
			));
		}
	}

	static public function format_date($time)
	{
		static $zone_offset;
		static $offset_string;

		if (empty($offset_string))
		{
			global $user;

			$zone_offset = $user->create_datetime()->getOffset();
			$offset_string = phpbb_format_timezone_offset($zone_offset);
		}

		return gmdate("Y-m-d\TH:i:s", $time + $zone_offset) . $offset_string;
	}
}
