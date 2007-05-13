<?php
/**
 * MyBB 1.2
 * Copyright � 2006 MyBB Group, All Rights Reserved
 *
 * Website: http://www.mybboard.net
 * License: http://www.mybboard.net/eula.html
 *
 * $Id$
 */

define("IN_MYBB", 1);

$templatelist = "newthread,previewpost,error_invalidforum,redirect_newthread,loginbox,changeuserbox,newthread_postpoll,posticons,attachment,newthread_postpoll,codebuttons,smilieinsert,error_nosubject";
$templatelist .= "posticons,newthread_disablesmilies,newreply_modoptions,post_attachments_new,post_attachments,post_savedraftbutton";

require_once "./global.php";
require_once MYBB_ROOT."inc/functions_post.php";
require_once MYBB_ROOT."inc/functions_user.php";

// Load global language phrases
$lang->load("newthread");

$tid = $pid = "";
if($mybb->input['action'] == "editdraft" || ($mybb->input['savedraft'] && $mybb->input['tid']) || ($mybb->input['tid'] && $mybb->input['pid']))
{
	$thread = get_thread($mybb->input['tid']);
	
	$query = $db->simple_select(TABLE_PREFIX."posts", "*", "tid='".intval($mybb->input['tid'])."' AND visible='-2'", array('order_by' => 'dateline', 'limit' => 1));
	$post = $db->fetch_array($query);

	if(!$thread['tid'] || !$post['pid'] || $thread['visible'] != -2)
	{
		error($lang->invalidthread);
	}
	
	$pid = $post['pid'];
	$fid = $thread['fid'];
	$tid = $thread['tid'];
	$editdraftpid = "<input type=\"hidden\" name=\"pid\" value=\"$pid\" /><input type=\"hidden\" name=\"tid\" value=\"$tid\" />";
}
else
{
	$fid = intval($mybb->input['fid']);
}

// Fetch forum information.
$forum = get_forum($fid);
if(!$forum)
{
	error($lang->error_invalidforum);
}

// Draw the navigation
build_forum_breadcrumb($fid);
add_breadcrumb($lang->nav_newthread);

$forumpermissions = forum_permissions($fid);

if($forum['open'] == "no" || $forum['type'] != "f")
{
	error($lang->error_closedinvalidforum);
}

if($forumpermissions['canview'] == "no" || $forumpermissions['canpostthreads'] == "no")
{
	error_no_permission();
}
// Check if this forum is password protected and if we've got the right password to access it.
check_forum_password($fid, $forum['password']);

// If MyCode is on for this forum and the MyCode editor is enabled inthe Admin CP, draw the code buttons and smilie inserter.
if($mybb->settings['bbcodeinserter'] != "off" && $forum['allowmycode'] != "no" && (!$mybb->user['uid'] || $mybb->user['showcodebuttons'] != 0))
{
	$codebuttons = build_mycode_inserter();
	if($forum['allowsmilies'] != "no")
	{
		$smilieinserter = build_clickable_smilies();
	}
}

// Does this forum allow post icons? If so, fetch the post icons.
if($forum['allowpicons'] != "no")
{
	$posticons = get_post_icons();
}

// If we have a currently logged in user then fetch the change user box.
if($mybb->user['uid'] != 0)
{
	eval("\$loginbox = \"".$templates->get("changeuserbox")."\";");
}

// Otherwise we have a guest, determine the "username" and get the login box.
else
{
	if(!$mybb->input['previewpost'] && $mybb->input['action'] != "do_newthread")
	{
		$username = $lang->guest;
	}
	else
	{
		$username = htmlspecialchars($mybb->input['username']);
	}
	eval("\$loginbox = \"".$templates->get("loginbox")."\";");
}

// If we're not performing a new thread insert and not editing a draft then we're posting a new thread.
if($mybb->input['action'] != "do_newthread" && $mybb->input['action'] != "editdraft")
{
	$mybb->input['action'] = "newthread";
}

// Previewing a post, overwrite the action to the new thread action.
if($mybb->input['previewpost'])
{
	$mybb->input['action'] = "newthread";
}

// Handle attachments if we've got any.
if(!$mybb->input['attachmentaid'] && ($mybb->input['newattachment'] || ($mybb->input['action'] == "do_newthread" && $mybb->input['submit'] && $_FILES['attachment'])))
{
	// If there's an attachment, check it and upload it
	if($_FILES['attachment']['size'] > 0 && $forumpermissions['canpostattachments'] != "no")
	{
		require_once MYBB_ROOT."inc/functions_upload.php";
		$attachedfile = upload_attachment($_FILES['attachment']);
	}
	
	// Error with attachments - should use new inline errors?
	if($attachedfile['error'])
	{
		eval("\$attacherror = \"".$templates->get("error_attacherror")."\";");
		$mybb->input['action'] = "newthread";
	}
	
	// If we were dealing with an attachment but didn't click 'Post Thread', force the new thread page again.
	if(!$mybb->input['submit'])
	{
		$mybb->input['action'] = "newthread";
	}
}

// Are we removing an attachment from the thread?
if($mybb->input['attachmentaid'])
{
	require_once MYBB_ROOT."inc/functions_upload.php";
	remove_attachment(0, $mybb->input['posthash'], $mybb->input['attachmentaid']);
	if(!$mybb->input['submit'])
	{
		$mybb->input['action'] = "newthread";
	}
}

$thread_errors = "";
$hide_captcha = false;

// Check the maximum posts per day for this user
if($mybb->settings['maxposts'] > 0 && $mybb->usergroup['cancp'] != "yes")
{
	$daycut = time()-60*60*24;
	$query = $db->simple_select(TABLE_PREFIX."posts", "COUNT(*) AS posts_today", "uid='{$mybb->user['uid']}' AND visible='1' AND dateline>{$daycut}");
	$post_count = $db->fetch_field($query, "posts_today");
	if($post_count >= $mybb->settings['maxposts'])
	{
		$lang->error_maxposts = sprintf($lang->error_maxposts, $mybb->settings['maxposts']);
		error($lang->error_maxposts);
	}
}

// Performing the posting of a new thread.
if($mybb->input['action'] == "do_newthread" && $mybb->request_method == "post")
{
	$plugins->run_hooks("newthread_do_newthread_start");

	// If this isn't a logged in user, then we need to do some special validation.
	if($mybb->user['uid'] == 0)
	{
		$username = htmlspecialchars_uni($mybb->input['username']);
	
		// Check if username exists.
		if(username_exists($mybb->input['username']))
		{
			// If it does and no password is given throw back "username is taken"
			if(!$mybb->input['password'])
			{
				error($lang->error_usernametaken);
			}
			
			//Checks to make sure the user can login; they haven't had too many tries at logging in.
			//Is a fatal call if user has had too many tries
			$logins = login_attempt_check();		

			// If the user specified a password but it is wrong, throw back invalid password.
			$mybb->user = validate_password_from_username($mybb->input['username'], $mybb->input['password']);
			if(!$mybb->user['uid'])
			{
				my_setcookie('loginattempts', $logins + 1);
				$db->query("UPDATE ".TABLE_PREFIX."sessions SET loginattempts=loginattempts+1 WHERE sid = '{$session->sid}'");
				if($mybb->settings['failedlogintext'] == "yes")
				{
					$login_text = sprintf($lang->failed_login_again, $mybb->settings['failedlogincount'] - $logins);
				}				
				error($lang->error_invalidpassword.$login_text);
			}
			// Otherwise they've logged in successfully.

			$mybb->input['username'] = $username = $mybb->user['username'];
			my_setcookie("mybbuser", $mybb->user['uid']."_".$mybb->user['loginkey'], null, true);
			my_setcookie('loginattempts', 1);
			
			// Update the session to contain their user ID
			$updated_session = array(
				"uid" => $mybb->user['uid'],
				"loginattempts" => 0
			);
			$db->update_query(TABLE_PREFIX."sessions", $updated_session, "sid='{$session->sid}'");
			
			// Set uid and username
			$uid = $mybb->user['uid'];
			$username = $mybb->user['username'];
		}
		// This username does not exist.
		else
		{
			// If they didn't specify a username then give them "Guest"
			if(!$mybb->input['username'])
			{
				$username = $lang->guest;
			}
			// Otherwise use the name they specified.
			else
			{
				$username = htmlspecialchars($mybb->input['username']);
			}
			$uid = 0;
		}
	}
	// This user is logged in.
	else
	{
		$username = $mybb->user['username'];
		$uid = $mybb->user['uid'];
	}
	
	// Attempt to see if this post is a duplicate or not
	if($uid > 0)
	{
		$user_check = "p.uid='{$uid}'";
	}
	else
	{
		$user_check = "p.ipaddress='".$db->escape_string($session->ipaddress)."'";
	}
	if(!$mybb->input['savedraft'] && !$pid)
	{
		$query = $db->simple_select(TABLE_PREFIX."posts p", "p.pid", "$user_check AND p.fid='{$forum['fid']}' AND p.subject='".$db->escape_string($mybb->input['subject'])."' AND p.message='".$db->escape_string($mybb->input['message'])."' AND p.posthash='".$db->escape_string($mybb->input['posthash'])."'");
		$duplicate_check = $db->fetch_field($query, "pid");
		if($duplicate_check)
		{
			error($lang->error_post_already_submitted);
		}
	}
	
	// Set up posthandler.
	require_once MYBB_ROOT."inc/datahandlers/post.php";
	$posthandler = new PostDataHandler("insert");
	$posthandler->action = "thread";

	// Set the thread data that came from the input to the $thread array.
	$new_thread = array(
		"fid" => $forum['fid'],
		"subject" => $mybb->input['subject'],
		"icon" => $mybb->input['icon'],
		"uid" => $uid,
		"username" => $username,
		"message" => $mybb->input['message'],
		"ipaddress" => get_ip(),
		"posthash" => $mybb->input['posthash']
	);
	
	if($pid != '')
	{
		$new_thread['pid'] = $pid;
	}

	// Are we saving a draft thread?
	if($mybb->input['savedraft'] && $mybb->user['uid'])
	{
		$new_thread['savedraft'] = 1;
	}
	else
	{
		$new_thread['savedraft'] = 0;
	}
	
	// Is this thread already a draft and we're updating it?
	if(isset($thread['tid']) && $thread['visible'] == -2)
	{
		$new_thread['tid'] = $thread['tid'];
	}

	// Set up the thread options from the input.
	$new_thread['options'] = array(
		"signature" => $mybb->input['postoptions']['signature'],
		"emailnotify" => $mybb->input['postoptions']['emailnotify'],
		"disablesmilies" => $mybb->input['postoptions']['disablesmilies']
	);
	
	// Apply moderation options if we have them
	$new_thread['modoptions'] = $mybb->input['modoptions'];

	$posthandler->set_data($new_thread);
	
	// Now let the post handler do all the hard work.
	$valid_thread = $posthandler->validate_thread();
	
	$post_errors = array();
	// Fetch friendly error messages if this is an invalid thread
	if(!$valid_thread)
	{
		$post_errors = $posthandler->get_friendly_errors();
	}
	
	
	// Check captcha image
	if($mybb->settings['captchaimage'] == "on" && function_exists("imagepng") && !$mybb->user['uid'])
	{
		$imagehash = $db->escape_string($mybb->input['imagehash']);
		$imagestring = $db->escape_string($mybb->input['imagestring']);
		$query = $db->simple_select(TABLE_PREFIX."captcha", "*", "imagehash='$imagehash'"); 
		$imgcheck = $db->fetch_array($query);
		if(strtolower($imgcheck['imagestring']) != strtolower($imagestring) || !$imgcheck['imagehash'])
		{
			$post_errors[] = $lang->invalid_captcha;
		}
		else
		{
			$db->delete_query(TABLE_PREFIX."captcha", "imagehash='$imagehash'");
			$hide_captcha = true;
		}
	}

	
	// One or more erors returned, fetch error list and throw to newthread page
	if(count($post_errors) > 0)
	{
		$thread_errors = inline_error($post_errors);
		$mybb->input['action'] = "newthread";		
	}
	// No errors were found, it is safe to insert the thread.
	else
	{
		$thread_info = $posthandler->insert_thread();
		$tid = $thread_info['tid'];
		$visible = $thread_info['visible'];
		
		// We were updating a draft thread, send them back to the draft listing.
		if($new_thread['savedraft'] == 1)
		{
			$lang->redirect_newthread = $lang->draft_saved;
			$url = "usercp.php?action=drafts";
		}
		
		// A poll was being posted with this thread, throw them to poll posting page.
		else if($mybb->input['postpoll'] && $forumpermissions['canpostpolls'])
		{
			$url = "polls.php?action=newpoll&tid=$tid&polloptions=".intval($mybb->input['numpolloptions']);
			$lang->redirect_newthread .= $lang->redirect_newthread_poll;
		}
		
		// This thread is stuck in the moderation queue, send them back to the forum.
		else if(!$visible)
		{
			// Moderated thread
			$lang->redirect_newthread .= $lang->redirect_newthread_moderation;
			$url = "forumdisplay.php?fid=$fid";
		}

		// This is just a normal thread - send them to it.
		else
		{
			// Visible thread
			$lang->redirect_newthread .= $lang->redirect_newthread_thread;
			$url = "showthread.php?tid=$tid";
		}

		$plugins->run_hooks("newthread_do_newthread_end");
		
		// Hop to it! Send them to the next page.
		if(!$mybb->input['postpoll'])
		{
			$lang->redirect_newthread .= sprintf($lang->redirect_return_forum, $fid);
		}
		redirect($url, $lang->redirect_newthread);
	}
}

if($mybb->input['action'] == "newthread" || $mybb->input['action'] == "editdraft")
{

	$plugins->run_hooks("newthread_start");

	// Check the various post options if we're
	// a -> previewing a post
	// b -> removing an attachment
	// c -> adding a new attachment
	// d -> have errors from posting
	
	if($mybb->input['previewpost'] || $mybb->input['attachmentaid'] || $mybb->input['newattachment'] || $thread_errors)
	{
		$postoptions = $mybb->input['postoptions'];
		if($postoptions['signature'] == "yes")
		{
			$postoptionschecked['signature'] = "checked=\"checked\"";
		}
		if($postoptions['emailnotify'] == "yes")
		{
			$postoptionschecked['emailnotify'] = "checked=\"checked\"";
		}
		if($postoptions['disablesmilies'] == "yes")
		{
			$postoptionschecked['disablesmilies'] = "checked=\"checked\"";
		}
		if($mybb->input['postpoll'] == "yes")
		{
			$postpollchecked = "checked=\"checked\"";
		}
		$numpolloptions = intval($mybb->input['numpolloptions']);
	}
	
	// Editing a draft thread
	else if($mybb->input['action'] == "editdraft" && $mybb->user['uid'])
	{
		$message = htmlspecialchars_uni($post['message']);
		$subject = htmlspecialchars_uni($post['subject']);
		if($post['includesig'] != "no")
		{
			$postoptionschecked['signature'] = "checked=\"checked\"";
		}
		if($post['smilieoff'] == "yes")
		{
			$postoptionschecked['disablesmilies'] = "checked=\"checked\"";
		}
		$icon = $post['icon'];
	}
	
	// Otherwise, this is our initial visit to this page.
	else
	{
		if($mybb->user['signature'] != '')
		{
			$postoptionschecked['signature'] = "checked=\"checked\"";
		}
		if($mybb->user['emailnotify'] == "yes")
		{
			$postoptionschecked['emailnotify'] = "checked=\"checked\"";
		}
		$numpolloptions = "2";
	}
	
	// If we're preving a post then generate the preview.
	if($mybb->input['previewpost'])
	{
		// Set up posthandler.
		require_once MYBB_ROOT."inc/datahandlers/post.php";
		$posthandler = new PostDataHandler("insert");
		$posthandler->action = "thread";
	
		// Set the thread data that came from the input to the $thread array.
		$new_thread = array(
			"fid" => $forum['fid'],
			"subject" => $mybb->input['subject'],
			"icon" => $mybb->input['icon'],
			"uid" => $uid,
			"username" => $username,
			"message" => $mybb->input['message'],
			"ipaddress" => get_ip(),
			"posthash" => $mybb->input['posthash']
		);
		
		if($pid != '')
		{
			$new_thread['pid'] = $pid;
		}
		
		$posthandler->set_data($new_thread);

		// Now let the post handler do all the hard work.
		$valid_thread = $posthandler->verify_message();
		$valid_subject = $posthandler->verify_subject();
	
		$post_errors = array();
		// Fetch friendly error messages if this is an invalid post
		if(!$valid_thread || !$valid_subject)
		{
			$post_errors = $posthandler->get_friendly_errors();
		}
		
		// One or more erors returned, fetch error list and throw to newreply page
		if(count($post_errors) > 0)
		{
			$thread_errors = inline_error($post_errors);
		}
		else
		{		
			if(!$mybb->input['username'])
			{
				$mybb->input['username'] = $lang->guest;
			}
			if($mybb->input['username'] && !$mybb->user['uid'])
			{
				$mybb->user = validate_password_from_username($mybb->input['username'], $mybb->input['password']);
			}
			$query = $db->query("
				SELECT u.*, f.*
				FROM ".TABLE_PREFIX."users u
				LEFT JOIN ".TABLE_PREFIX."userfields f ON (f.ufid=u.uid)
				WHERE u.uid='".$mybb->user['uid']."'
			");
			$post = $db->fetch_array($query);
			if(!$mybb->user['uid'] || !$post['username'])
			{
				$post['username'] = htmlspecialchars_uni($mybb->input['username']);
			}
			else
			{
				$post['userusername'] = $mybb->user['username'];
				$post['username'] = $mybb->user['username'];
			}
			$previewmessage = $mybb->input['message'];
			$post['message'] = $previewmessage;
			$post['subject'] = $mybb->input['subject'];
			$post['icon'] = $mybb->input['icon'];
			$post['smilieoff'] = $postoptions['disablesmilies'];
			$post['dateline'] = time();
			$post['includesig'] = $mybb->input['postoptions']['signature'];
			if($post['includesig'] != "yes")
			{
				$post['includesig'] = "no";
			}

			// Fetch attachments assigned to this post
			if($mybb->input['pid'])
			{
				$attachwhere = "pid='".intval($mybb->input['pid'])."'";				
			}
			else
			{
				$attachwhere = "posthash='".$db->escape_string($mybb->input['posthash'])."'";
			}
	
			$query = $db->simple_select(TABLE_PREFIX."attachments", "*", $attachwhere);
			while($attachment = $db->fetch_array($query)) 
			{
				$attachcache[0][$attachment['aid']] = $attachment;
			}
	
			$postbit = build_postbit($post, 1);
			eval("\$preview = \"".$templates->get("previewpost")."\";");
		}
		$message = htmlspecialchars_uni($mybb->input['message']);
		$subject = htmlspecialchars_uni($mybb->input['subject']);
	}

	// Removing an attachment or adding a new one, or showting thread errors.
	else if($mybb->input['attachmentaid'] || $mybb->input['newattachment'] || $thread_errors) 
	{
		$message = htmlspecialchars_uni($mybb->input['message']);
		$subject = htmlspecialchars_uni($mybb->input['subject']);
	}

	// Setup a unique posthash for attachment management
	if(!$mybb->input['posthash'] && $mybb->input['action'] != "editdraft")
	{
	    mt_srand ((double) microtime() * 1000000);
	    $posthash = md5($mybb->user['uid'].mt_rand());
	}
	else
	{
		$posthash = htmlspecialchars($mybb->input['posthash']);
	}

	// Can we disable smilies or are they disabled already?
	if($forum['allowsmilies'] != "no")
	{
		eval("\$disablesmilies = \"".$templates->get("newthread_disablesmilies")."\";");
	}
	else
	{
		$disablesmilies = "<input type=\"hidden\" name=\"postoptions[disablesmilies]\" value=\"no\" />";
	}

	// Show the moderator options
	if(is_moderator($fid) == "yes")
	{
		$modoptions = $mybb->input['modoptions'];
		if($modoptions['closethread'] == "yes")
		{
			$closecheck = "checked=\"checked\"";
		}
		else
		{
			$closecheck = '';
		}
		if($modoptions['stickthread'] == "yes")
		{
			$stickycheck = "checked=\"checked\"";
		}
		else
		{
			$stickycheck = '';
		}
		unset($modoptions);
		$bgcolor = "trow2";
		eval("\$modoptions = \"".$templates->get("newreply_modoptions")."\";");
		$bgcolor = "trow1";
	}
	else
	{
		$bgcolor = "trow2";
	}

	if($forumpermissions['canpostattachments'] != "no")
	{ // Get a listing of the current attachments, if there are any
		$attachcount = 0;
		if($mybb->input['action'] == "editdraft" || ($mybb->input['tid'] && $mybb->input['pid']))
		{
			$attachwhere = "pid='$pid'";
		}
		else
		{
			$attachwhere = "posthash='".$db->escape_string($posthash)."'";
		}
		$query = $db->simple_select(TABLE_PREFIX."attachments", "*", $attachwhere);
		$attachments = '';
		while($attachment = $db->fetch_array($query))
		{
			$attachment['size'] = get_friendly_size($attachment['filesize']);
			$attachment['icon'] = get_attachment_icon(get_extension($attachment['filename']));
			if($forum['allowmycode'] != "no")
			{
				eval("\$postinsert = \"".$templates->get("post_attachments_attachment_postinsert")."\";");
			}
			$attach_mod_options = '';
			if($attachment['visible'] != 1)
			{
				eval("\$attachments .= \"".$templates->get("post_attachments_attachment_unapproved")."\";");
			}
			else
			{
				eval("\$attachments .= \"".$templates->get("post_attachments_attachment")."\";");
			}
			$attachcount++;
		}
		$query = $db->simple_select(TABLE_PREFIX."attachments", "SUM(filesize) AS ausage", "uid='".$mybb->user['uid']."'");
		$usage = $db->fetch_array($query);
		if($usage['ausage'] > ($mybb->usergroup['attachquota']*1024) && $mybb->usergroup['attachquota'] != 0)
		{
			$noshowattach = 1;
		}
		if($mybb->usergroup['attachquota'] == 0)
		{
			$friendlyquota = $lang->unlimited;
		}
		else
		{
			$friendlyquota = get_friendly_size($mybb->usergroup['attachquota']*1024);
		}
		$friendlyusage = get_friendly_size($usage['ausage']);
		$lang->attach_quota = sprintf($lang->attach_quota, $friendlyusage, $friendlyquota);
		if($mybb->settings['maxattachments'] == 0 || ($mybb->settings['maxattachments'] != 0 && $attachcount < $mybb->settings['maxattachments']) && !$noshowattach)
		{
			eval("\$newattach = \"".$templates->get("post_attachments_new")."\";");
		}
		eval("\$attachbox = \"".$templates->get("post_attachments")."\";");

		$bgcolor = alt_trow();
	}

	if($mybb->user['uid'])
	{
		eval("\$savedraftbutton = \"".$templates->get("post_savedraftbutton")."\";");
	}
	
	// Show captcha image for guests if enabled
	if($mybb->settings['captchaimage'] == "on" && function_exists("imagepng") && !$mybb->user['uid'])
	{
		$correct = false;
		// If previewing a post - check their current captcha input - if correct, hide the captcha input area
		if($mybb->input['previewpost'] || $hide_captcha == true)
		{
			$imagehash = $db->escape_string($mybb->input['imagehash']);
			$imagestring = $db->escape_string($mybb->input['imagestring']);
			$query = $db->simple_select(TABLE_PREFIX."captcha", "*", "imagehash='$imagehash' AND imagestring='$imagestring'");
			$imgcheck = $db->fetch_array($query);
			if($imgcheck['dateline'] > 0)
			{
				eval("\$captcha = \"".$templates->get("post_captcha_hidden")."\";");			
				$correct = true;
			}
			else
			{
				$db->delete_query(TABLE_PREFIX."captcha", "imagehash='$imagehash'");
			}
		}
		if(!$correct)
		{	
			$randomstr = random_str(5);
			$imagehash = md5($randomstr);
			$imagearray = array(
				"imagehash" => $imagehash,
				"imagestring" => $randomstr,
				"dateline" => time()
			);
			$db->insert_query(TABLE_PREFIX."captcha", $imagearray);
			eval("\$captcha = \"".$templates->get("post_captcha")."\";");			
		}
	}
	
	if($forumpermissions['canpostpolls'] != "no")
	{
		$lang->max_options = sprintf($lang->max_options, $mybb->settings['maxpolloptions']);
		eval("\$pollbox = \"".$templates->get("newthread_postpoll")."\";");
	}

	$plugins->run_hooks("newthread_end");
	
	$lang->newthread_in = sprintf($lang->newthread_in, $forum['name']);

	eval("\$newthread = \"".$templates->get("newthread")."\";");
	output_page($newthread);

}
?>