<?php

WP_CLI::addCommand('blog', 'BlogCommand');

/**
 * Implement core command
 *
 * @package wp-cli
 * @subpackage commands/internals
 */
class BlogCommand extends WP_CLI_Command {
	
	//@TODO make associative due to optional params
	private function _create_usage_string() {
		return "usage: wp blog create --domain_base=<subdomain or directory name> --title=<blog title> [--email] [--site_id] [--public]";
	}
	
	private function _get_site($site_id) {
		global $wpdb;
		// Load site data
		$sites = $wpdb->get_results("SELECT * FROM $wpdb->site WHERE `id` = ".$site_id);
		if (count($sites) > 0) {
			// Only care about domain and path which are set here
			return $sites[0];
		}
		
		return false;
	}
	
	public function create($args, $assoc_args) {
		if (!is_multisite()) {
			WP_CLI::line("ERROR: not a multisite instance");
			exit;
		}
		global $wpdb;
		
		// domain required
		// title required
		// email optional
		// site optional
		// public optional
		if (empty($assoc_args['domain_base']) || empty($assoc_args['title'])) {
			WP_CLI::line($this->_create_usage_string());
			exit;
		}
		
		$base = $assoc_args['domain_base'];
		$title = $assoc_args['title'];
		$email = empty($assoc_args['email']) ? '' : $assoc_args['email'];
		// Site
		if (!empty($assoc_args['site_id'])) {
			$site = $this->_get_site($assoc_args['site_id']);
			if ($site === false) {
				WP_CLI::line('ERROR: Site with id '.$assoc_args['site_id'].'does not exist');
				exit;
			}
		}
		else {
			$site = wpmu_current_site();
		}
		// Public
		if (!empty($assoc_args['public'])) {
			$public = $args['public'];
			// Check for 1 or 0
			if ($public != '1' && $public != '0') {
				$this->_create_usage();
			}
		}
		else {
			$public = 1;
		}

		if (preg_match( '|^([a-zA-Z0-9-])+$|', $base)) {
			$base = strtolower($base);
		}
		
		// If not a subdomain install, make sure the domain isn't a reserved word
		if (!is_subdomain_install()) {
			$subdirectory_reserved_names = apply_filters('subdirectory_reserved_names', array( 'page', 'comments', 'blog', 'files', 'feed' ));
			if (in_array($domain, $subdirectory_reserved_names)) {
				WP_CLI::line(sprintf(__('ERROR: The following words are reserved for use by WordPress functions and cannot be used as blog names: <code>%s</code>'), implode('</code>, <code>', $subdirectory_reserved_names)));
				exit;
			}
		}
		

		// Check for valid email, if not, use the first Super Admin found
		// Probably a more efficient way to do this so we dont query for the
		// User twice if super admin
		$email = sanitize_email($email);
		if (empty($email) || !is_email($email)) {
			$super_admins = get_super_admins();
			$email = '';
			if (!empty($super_admins) && is_array($super_admins)) {
				// Just get the first one
				$super_login = $super_admins[0];
				$super_user = get_user_by('login', $super_login);
				if ($super_user) {
					error_log($super_user->user_email);
					error_log($super_user->email);
					$email = $super_user->user_email;
				}
			}
		}

		if (is_subdomain_install()) {			
			$path = '/';
			$url = $newdomain = $base.'.'.preg_replace('|^www\.|', '', $site->domain);
			
		} 
		else {
			$newdomain = $site->domain;
			$path = $base;
			if (strpos($path, '/') !== 0) {
				$path = '/'.$path;
			}
			$url = $site->domain.$path;
		}
		
		$password = 'N/A';
		$user_id = email_exists($email);
		if (!$user_id) { // Create a new user with a random password
			$password = wp_generate_password(12, false);
			$user_id = wpmu_create_user($base, $password, $email);
			if (false == $user_id) {
				WP_CLI::line('ERROR: There was an issue creating the user.');
				exit;
			}
			else {
				wp_new_user_notification($user_id, $password);
			}
		}
		
		$wpdb->hide_errors();
		$id = wpmu_create_blog($newdomain, $path, $title, $user_id, array( 'public' => $public ), $site->id);
		$wpdb->show_errors();
		if (!is_wp_error($id)) {
			if ( !is_super_admin($user_id) && !get_user_option('primary_blog', $user_id)) {
				update_user_option($user_id, 'primary_blog', $id, true);
			}
//			$content_mail = sprintf(__( "New site created by WP Command Line Interface\n\nAddress: %2s\nName: %3s"), get_site_url($id), stripslashes($title));
			//@TODO Current site
//			wp_mail(get_site_option('admin_email'), sprintf(__('[%s] New Site Created'), $current_site->site_name), $content_mail, 'From: "Site Admin" <'.get_site_option( 'admin_email').'>');
		} 
		else {
			WP_CLI::line('ERROR: '.$id->get_error_message());
			exit;
		}	
		WP_CLI::line('Blog created with DOMAIN: '.$site->domain.' URL: '.$url.' ID: '.$id);
	}
		
	public function update($args) {}
		
	public function delete($args) {}
		
	public function help() {
		WP_CLI::line(<<<EOB
usage: wp blog <sub-command> [options]

Available sub-commands:
   create   create a new blog
     --domain_base    Base for the new domain. Subdomain on subdomain installs, directory on subdirectory installs
     --title          Title of the new blog
     [--email]        Email for Admin user. User will be created if none exists. Assignement to Super Admin if not included
     [--site_id]      Site to associate new blog with. Defaults to current site (typically 1)
     [--public]       Whether or not the new site is public (indexed)

   update   //TODO

   delete   //TODO
EOB
	);
	}
}