<?php
/**
 * Includes the constants and defines the class for setting up multiple levels of super admins
 * @package WordPress
 * @subpackage ExtendedSuperAdmins
 * @since 0.1a
 * @version 0.6a
 */

/**
 * Require the file that sets the constants for this plugin
 * @package WordPress
 * @subpackage ExtendedSuperAdmins
 */
require_once( str_replace( 'class-', 'constants-', __FILE__ ) );

if( !class_exists( 'extended_super_admins' ) ) {
	/**
	 * A class to handle multiple levels of "Super Admins" in WordPress
	 * @package WordPress
	 * @subpackage ExtendedSuperAdmins
	 */
	class extended_super_admins {
		/**
		 * An array of identifiers for the different roles
		 * @var array
		 */
		var $role_id = array();
		/**
		 * An array of the friendly names for these roles
		 * @var array
		 */
		var $role_name = array();
		/**
		 * An array of the capabilities to be removed from each role
		 * @var array
		 */
		var $role_caps = array();
		/**
		 * An array of the user logins that belong to each role
		 * @var array
		 */
		var $role_members = array();
		/**
		 * An internal array of the options as sent to/retrieved from the database
		 * @var array
		 */
		var $options = array();
		/**
		 * An internal array of the capabilities available
		 * @var array
		 */
		var $allcaps = array();
		/**
		 * A variable to determine whether we've checked this user's permissions yet
		 * @var bool
		 */
		var $perms_checked = false;
		/**
		 * An array to hold the Codex descriptions of each capability
		 * @var array
		 * @since 0.4a
		 */
		var $caps_descriptions = array(
			'manage_esa_options' => '<p>Capability specific to the Extended Super Admins plugin. Allows user to manage the options for the Extended Super Admins plugin.</p>',
		);
		
		/**
		 * Create our extended_super_admins object
		 */
		function __construct() {
			if( !is_multisite() || !is_user_logged_in() )
				return false;
			
			$this->set_options();
			add_role( 'esa_plugin_manager', 'Extended Super Admin Manager', array( 'manage_esa_options' ) );
			
			$this->can_manage_plugin();
			
			add_filter( 'map_meta_cap', array( $this, 'revoke_privileges' ), 0, 4 );
			add_action( 'network_admin_menu', array( $this, 'add_submenu_page' ) );
			add_action( 'admin_menu', array( $this, 'add_submenu_page' ) );
			add_action( 'init', array( $this, '_init' ) );
			add_action( 'admin_init', array( $this, '_admin_init' ) );
			add_filter('plugin_action_links_' . ESA_PLUGIN_BASENAME, array($this, 'add_settings_link'));
			add_filter('network_admin_plugin_action_links_' . ESA_PLUGIN_BASENAME, array($this, 'add_settings_link'));
			
			return true;
		}
		
		function _init() {
			if( function_exists( 'load_plugin_textdomain' ) )
				load_plugin_textdomain( ESA_TEXT_DOMAIN, false, ESA_PLUGIN_PATH . '/lang/' );
				
			if( is_admin() && isset( $_REQUEST['page'] ) && $_REQUEST['page'] == ESA_OPTIONS_PAGE ) {
				if( function_exists( 'wp_register_style' ) ) {
					wp_register_style( 'esa_admin_styles', plugins_url( 'css/extended_super_admins.css', __FILE__ ), array(), '0.6a', 'all' );
				}
				if( function_exists( 'wp_register_script' ) ) {
					wp_register_script( 'esa_admin_scripts', plugins_url( 'scripts/extended_super_admins.js', __FILE__ ), array('jquery','post'), '0.6a', true );
				}
				
				if( version_compare( '3.0.9', $GLOBALS['wp_version'], '<' ) ) {
					add_action( 'load-settings_page_' . ESA_OPTIONS_PAGE, array( $this, 'add_settings_meta_boxes' ) );
					add_action( 'load-settings_page_' . ESA_OPTIONS_PAGE, array( $this, '_save_settings_options' ) );
				} else {
					add_action( '_admin_menu', array( $this, 'add_settings_meta_boxes' ) );
					add_action( '_admin_menu', array( $this, '_save_settings_options' ) );
				}
			}
				
			return true;
		}
		
		function _admin_init() {
			if( isset( $_REQUEST['page'] ) && $_REQUEST['page'] == ESA_OPTIONS_PAGE ) {
				if( function_exists( 'wp_enqueue_script' ) ) {
					wp_enqueue_script( 'esa_admin_scripts' );
				}
				
				if( function_exists( 'wp_enqueue_style' ) ) {
					wp_enqueue_style( 'esa_admin_styles' );
				}
				
				if( function_exists( 'register_setting' ) )
					register_setting( ESA_OPTION_NAME, ESA_OPTION_NAME, array( $this, 'verify_options' ) );
				else
					/*wp_die( 'The <code>register_setting()</code> function is not available.' );*/
					add_filter( 'sanitize_option_' . ESA_OPTION_NAME, array( $this, 'verify_options' ) );
			}
		}
		
		function add_settings_link( $links ) {
			global $wp_version;
			$options_page = ( version_compare( $wp_version, '3.0.9', '>' ) ) ? 'settings' : 'ms-admin';
			$settings_link = '<a href="' . $options_page . '.php?page=' . ESA_OPTIONS_PAGE . '">';
			$settings_link .= __( 'Settings', ESA_TEXT_DOMAIN );
			$settings_link .= '</a>';
			
			$del_settings_link = '<a href="' . $options_page . '.php?page=' . ESA_OPTIONS_PAGE . '&options-action=remove_settings">';
			$del_settings_link .= __( 'Delete Settings', ESA_TEXT_DOMAIN );
			$del_settings_link .= '</a>';
			
			array_unshift( $links, $settings_link );
			array_push( $links, $del_settings_link );
			return $links;
		}
		
		/**
		 * Determine whether or not this user is allowed to modify this plugin's options
		 */
		function can_manage_plugin() {
			if( $this->perms_checked )
				return current_user_can( 'manage_esa_options' );
			
			global $current_user;
			get_currentuserinfo();
			
			if( is_super_admin() && current_user_can( 'manage_network_plugins' ) && current_user_can( 'manage_network_users' ) ) {
				$current_user->add_cap( 'manage_esa_options' );
				return true;
			} else {
				$current_user->remove_cap( 'manage_esa_options' );
				return false;
			}
		}
		
		/**
		 * Set the object's parameters
		 * @param array the values to use to set the options
		 */
		function set_options( $values_to_use=NULL ) {
			$options_set = false;
			if( !empty( $values_to_use ) ) {
				$options_set = true;
				
				$this->options = array(
					'role_id'		=> $values_to_use['role_id'],
					'role_name'		=> $values_to_use['role_name'],
					'role_members'	=> $values_to_use['role_members'],
					'role_caps'		=> $values_to_use['role_caps'],
				);
				foreach( $this->options['role_id'] as $id ) {
					if( empty( $this->options['role_name'][$id] ) ) {
						unset( $this->options['role_id'][$id], $this->options['role_name'][$id], $this->options['role_members'][$id], $this->options['role_caps'][$id] );
						$this->debug .= '<div class="error">' . __( 'One of the roles you attempted to create did not have a name. Therefore it was not saved. Please try again.', ESA_TEXT_DOMAIN ) . '</div>';
					} else {
						if( empty( $this->options['role_members'][$id] ) ) {
							$this->options['role_members'][$id] = array(0=>NULL);
						}
						if( empty( $this->options['role_caps'][$id] ) ) {
							$this->options['role_caps'][$id] = array(0=>NULL);
						}
					}
					
					if( ( empty( $this->options['role_name'][$id] ) && empty( $this->options['role_members'][$id] ) && empty( $this->options['role_caps'][$id] ) ) || ( isset($values_to_use['delete_role'][$id] ) && $values_to_use['delete_role'][$id] == 'on' ) ) {
						unset( $this->options['role_id'][$id], $this->options['role_name'][$id], $this->options['role_members'][$id], $this->options['role_caps'][$id] );
					}
				}
				if( empty( $this->options ) ) {
					delete_site_option( ESA_OPTION_NAME );
					add_site_option( ESA_OPTION_NAME, array() );
				}
			}
			
			if( empty( $this->options ) ) {
				$this->options = get_site_option( ESA_OPTION_NAME, array(), false );
			}
			
			if( empty( $this->options ) ) {
				add_site_option( ESA_OPTION_NAME, array() );
				$this->options = array();
			}
			
			foreach( $this->options as $key=>$var ) {
				if( !is_array( $var ) ) {
					continue;
				}
				if( property_exists( $this, $key ) ) {
					$this->$key = $var;
				}
			}
			return;
		}
		
		/**
		 * Find out whether the WP Multi Network plugin is active
		 */
		function is_multi_network() {
			if( isset( $this->is_multi_network ) )
				return $this->is_multi_network;
			
			if( function_exists( 'wpmn_switch_to_network' ) || function_exists( 'switch_to_site' ) ) {
				$this->is_multi_network = true;
				return $this->is_multi_network;
			}
				
			if( !file_exists( WP_PLUGIN_DIR . '/wordpress-multi-network/wordpress-multi-network.php' ) && !file_exists( WPMU_PLUGIN_DIR . '/wordpress-multi-network/wordpress-multi-network.php' ) && !file_exists( WP_PLUGIN_DIR . '/networks-for-wordpress/index.php' ) && !file_exists( WPMU_PLUGIN_DIR . '/networks-for-wordpress/index.php' ) ) {
				$this->is_multi_network = false;
				return $this->is_multi_network;
			}
			
			global $wpdb;
			$plugins = $wpdb->get_results( $wpdb->prepare( "SELECT meta_value FROM " . $wpdb->sitemeta . " WHERE meta_key = 'active_sitewide_plugins'" ) );
			foreach( $plugins as $plugin ) {
				if( in_array( 'wordpress-multi-network/wordpress-multi-network.php', maybe_unserialize( $plugin->meta_value ) ) || in_array( 'networks-for-wordpress/index.php', maybe_unserialize( $plugin->meta_value ) ) ) {
					$this->is_multi_network = true;
					return $this->is_multi_network;
				}
			}
			$sites = $wpdb->get_results( $wpdb->prepare( "SELECT blog_id FROM " . $wpdb->blogs ) );
			foreach( $sites as $site ) {
				$oldblog = $wpdb->set_blog_id( $site->blog_id );
				$plugins = $wpdb->get_results( $wpdb->prepare( "SELECT option_value FROM " . $wpdb->options . " WHERE option_name = 'active_plugins'" ) );
				foreach( $plugins as $plugin ) {
					if( in_array( 'wordpress-multi-network/wordpress-multi-network.php', maybe_unserialize( $plugin->option_value ) ) || in_array( 'networks-for-wordpress/index.php', maybe_unserialize( $plugin->option_value ) ) ) {
						$this->is_multi_network = true;
						return $this->is_multi_network;
					}
				}
			}
			
			$this->is_multi_network = false;
			return $this->is_multi_network;
		}
		
		/**
		 * Return the array of the options
		 * @return array the array of options (suitable for saving to the database)
		 */
		function get_options() {
			$options = array();
			$keys = array( 'role_id', 'role_name', 'role_caps', 'role_members' );
			foreach( $keys as $key ) {
				$options[$key] = $this->$key;
			}
			
			return $options;
		}
		
		function admin_notice() {
			if( empty( $this->debug ) )
				return;
			
			echo $this->debug;
			unset( $this->debug );
		}
		
		/**
		 * Save the options to the database 
		 */
		function save_options( $values_to_use=NULL ) {
			if( empty( $values_to_use ) ) {
				$this->debug = '<div class="error">';
				$this->debug .= '<p>' . __( 'The form to save the Extended Super Admin options was submitted, but the values were empty. Therefore, nothing was updated.', ESA_TEXT_DOMAIN ) . '</p>';
				$this->debug .= '</div>';
				return;
			}
			
			/*$this->set_options( $values_to_use );
			$this->options = $this->get_options();*/
			
			if( update_site_option( ESA_OPTION_NAME, $values_to_use ) ) {
				$this->debug = '<div class="updated">';
				$this->debug .= '<p>' . __( 'The options for the Extended Super Admins plugin have been updated.', ESA_TEXT_DOMAIN ) . '</p>';
				$this->debug .= '</div>';
			} else {
				$this->debug .= '<div class="error">';
				$this->debug .= '<p>' . __( 'There was an error committing the changes to the database.', ESA_TEXT_DOMAIN ) . '</p>';
				$this->debug .=  '</div>';
			}
			unset( $this->options );
			$this->set_options();
			return $this->options;
		}
		
		function verify_options( $input=NULL ) {
			/*global $site_id;
			$this->debug .= '<p>We are attempting to update the options for network ' . $site_id . '</p>';*/
			if( empty( $input ) && isset( $_POST['esa_options_action'] ) )
				$input = $_POST;
			
			$this->set_options( $input );
			$this->options = $this->get_options();
			return $this->options;
		}
		
		/**
		 * Remove items from the array of options
		 */
		function unset_options( $ids=array() ) {
			$keys = array( 'role_name', 'role_caps', 'role_members' );
			
			foreach( $ids as $id ) {
				unset( $this->role_id[$id], $this->role_name[$id], $this->role_caps[$id], $this->role_members[$id] );
			}
		}
		
		/**
		 * Remove this plugin's settings from the database
		 */
		function delete_settings() {
			delete_site_option( ESA_OPTION_NAME );
			return print('<div class="warning">The settings for this plugin have been deleted.</div>');
		}
		
		/**
		 * Perform the action of revoking specific privileges from the current user
		 */
		function revoke_privileges( $caps, $cap, $user_id, $args ) {
			if( $cap == 'manage_esa_options' )
				$this->perms_checked = true;
				
			if( !is_super_admin() ) {
				if( $cap == 'manage_esa_options' )
					return array_merge( $caps, array( 'do_not_allow' ) );
				
				return $caps;
			}
			
			global $current_user;
			get_currentuserinfo();
			$role_id = NULL;
			
			foreach( $this->role_members as $id=>$members ) {
				if( in_array( $current_user->user_login, $members ) ) {
					$role_id = $id;
					break;
				}
			}
			
			if( is_null( $role_id ) )
				return $caps;
			
			if( !is_array( $this->role_caps[$role_id] ) || !array_key_exists( $cap, $this->role_caps[$role_id] ) )
				return $caps;
				
			return array_merge( $caps, array( 'do_not_allow' ) );
		}
		
		function add_settings_meta_boxes() {
			$output = '';
			foreach( $this->role_id as $id ) {
				/*print("\n<!-- We are adding a new meta box for the item with an ID of $id -->\n");*/
				if( function_exists( 'add_meta_box' ) ) {
					add_meta_box( 'esa-options-meta-' . $id, $this->role_name[$id], array( $this, 'make_settings_meta_boxes' ), ESA_OPTIONS_PAGE, 'advanced', 'low', array( 'id' => $id ) );
				} else {
					wp_die( 'While trying to create the existing role meta boxes, we found that the meta box function does not exist' );
					$output .= $this->admin_options_section( $id );
				}
			}
			if( function_exists( 'add_meta_box' ) ) {
				if( empty( $this->role_id ) ) {
					$id = 1;
				} else {
					$id = ( max( $this->role_id ) + 1 );
				}
				/*print("\n<!-- We are adding a new meta box for a new role -->\n");*/
				add_meta_box( 'esa-options-meta-' . $id, __( 'Add a New Role', ESA_TEXT_DOMAIN ), array( $this, 'make_settings_meta_boxes' ), ESA_OPTIONS_PAGE, 'normal', 'high', array( 'id' => NULL ) );
			} else {
				wp_die( 'While trying to create a meta box for the new role, we found that the meta box function does not exist' );
				$output .= $this->admin_options_section();
			}
			if( !function_exists( 'add_meta_box' ) )
				return $output;
			else
				return NULL;
		}
		
		function _save_settings_options() {
			global $wp_version;
			/* We need to save our options if the form was already submitted */
			if( isset( $_POST['esa_options_action'] ) && wp_verify_nonce( $_POST['_wp_nonce'], 'esa_options_save' ) ) {
				if( $this->save_options( $_POST ) ) {
					if( version_compare( $wp_version, '3.0.9', '>' ) )
						wp_redirect(network_admin_url('settings.php?page=esa_options_page&action-message=updated'));
					else
						wp_redirect(admin_url('ms-admin.php?page=esa_options_page&action-message=updated'));
				} else {
					if( version_compare( $wp_version, '3.0.9', '>' ) )
						wp_redirect(network_admin_url('settings.php?page=esa_options_page&action-message=failed'));
					else
						wp_redirect(admin_url('ms-admin.php?page=esa_options_page&action-message=failed'));
				}
			} elseif( isset( $_POST['esa_options_action'] ) ) {
				$this->debug .= '<div class="warning">';
				$this->debug .= __( 'The nonce for these options could not be verified.', ESA_TEXT_DOMAIN );
				$this->debug .= '</div>';
			}
		}
		
		function admin_options_page() {
			if( !current_user_can( 'manage_esa_options' ) ) {
?>
<div class="wrap">
	<h2><?php _e('Extended Super Admin Settings', ESA_TEXT_DOMAIN) ?></h2>
    <p><?php _e('You do not have the appropriate permissions to modify the settings for this plugin. Please work with the network owner to update these settings.', ESA_TEXT_DOMAIN) ?></p>
</div>
<?php
				return;
			}
			if( isset( $_REQUEST['options-action'] ) ) {
				if( stristr( $_REQUEST['options-action'], 'multi_network_' ) ) {
					require_once( ESA_ABS_DIR . '/inc/multi_network_activation.php' );
					return;
				} elseif( $_REQUEST['options-action'] == 'remove_settings' ) {
					return $this->delete_settings();
				}
			}
			
			/* Start our output */
			$output = '
	<div class="wrap">';
			$output .= '
		<h2>' . __( 'Extended Super Admin Settings', ESA_TEXT_DOMAIN ) . '</h2>';
			if( isset( $_REQUEST['action-message'] ) ) {
				if( $_REQUEST['action-message'] == 'updated' )
					$output .= '<div class="updated">' . __( 'The options for this plugin were updated successfully.' ) . '</div>';
				else
					$output .= '<div class="error">' . __( 'There was an error updating the options for this plugin.' ) . '</div>';
			}
			$output .= '
		<div id="poststuff" class="metabox-holder">
			<div id="post-body">
				<div id="post-body-content">';
			if( !empty( $this->debug ) ) {
				$output .= $this->debug;
				unset( $this->debug );
			}
			$output .= '<p><em>' . __( 'In the lists of capabilities below, wherever (?) appears, you can click on that to view information from the WordPress Codex about that specific capability. The information is retrieved from the Codex once a week.', ESA_TEXT_DOMAIN ) . '</em></p><p><em>' . __( 'Don\'t like the description? Login to the WordPress Codex and edit it.', ESA_TEXT_DOMAIN ) . '</em></p>';
			$output .= '
		<form method="post" action="">';
			$output .= wp_nonce_field( 'esa_options_save', '_wp_nonce', true, false );
			$output .= wp_nonce_field('closedpostboxes', 'closedpostboxesnonce', false, false );
			$output .= wp_nonce_field('meta-box-order', 'meta-box-order-nonce', false, false );
			
			echo $output;
			$output = '';
			/* Output a set of option fields for each role that's already been created */
			if( !function_exists( 'add_meta_box' ) ) {
				wp_die( 'While outputting the admin page, we found that the meta box function does not exist' );
				$output .= $this->add_settings_meta_boxes();
			}
			if( empty( $output ) ) {
				do_meta_boxes( ESA_OPTIONS_PAGE, 'normal', NULL );
				do_meta_boxes( ESA_OPTIONS_PAGE, 'advanced', NULL );
			} else {
				echo $output;
			}

			$output = '
			<p class="submit">
	        	<input type="submit" class="button-primary" value="' . __('Save', ESA_TEXT_DOMAIN) . '"/>
			</p>
			<input type="hidden" name="esa_options_action" value="save"/>
		</form>';
			$output .= '
				</div><!-- #post-body-content -->
			</div><!-- #post-body -->
		</div><!-- #poststuff --><br class="clear">
	</div><!-- .wrap -->';
			echo $output;
		}
		
		function admin_options_section( $id=NULL ) {
			$new = false;
			if( !empty( $id ) ) {
				$role_id = $id;
			} else {
				$new = true;
				if( empty( $this->role_id ) ) {
					$id = 1;
				} else {
					$id = ( max( $this->role_id ) + 1 );
				}
				$role_id = $id;
				$this->role_id[$id] = $id;
				$this->role_name[$id] = '';
				$this->role_members[$id] = array();
				$this->role_caps[$id] = array();
			}
			$output = '
			<table class="form-table esa-options-table" id="esa-options-table-' . $id . '">
				<thead>
					<tr>
						<th' . ( ( $new ) ? ' colspan="2"' : '' ) . '>
							<h3>' . ( ( $new ) ? 'Add a New Role' : $this->role_name[$id] ) . '</h3>
							<input type="hidden" name="role_id[' . $id . ']" id="role_id_' . $id . '" value="' . $id . '"/>
						</th>' . ( ( $new ) ? '' : '
						<td>
							<label for="delete_role_' . $id . '">' . __( 'Would you like this role to be deleted?', ESA_TEXT_DOMAIN ) . '</label> <input type="checkbox" value="on" name="delete_role[' . $id . ']" id="delete_role_' . $id . '"/>
						</td>' ) . '
					</tr>
				</thead>
				<tbody id="esa_options_' . $id . '">';
			$output .= $this->role_name_options( $id );
			$output .= $this->role_caps_options( $id );
			$output .= $this->role_members_options( $id );
			$output .= '
				</tbody>
			</table>';
			return $output;
		}
		
		function make_settings_meta_boxes() {
			$id = NULL;
			
			$func_args = func_get_args();
			if( is_array( $func_args ) )
				$func_args = array_pop( $func_args );
			if( is_array( $func_args ) && array_key_exists( 'args', $func_args ) )
				$args = $func_args['args'];
			if( is_array( $args ) )
				$id = array_shift( $args );
			unset( $args, $func_args );
			
			$new = false;
			if( !empty( $id ) ) {
				$role_id = $id;
			} else {
				$new = true;
				if( empty( $this->role_id ) ) {
					$id = 1;
				} else {
					$id = ( max( $this->role_id ) + 1 );
				}
				$role_id = $id;
				$this->role_id[$id] = $id;
				$this->role_name[$id] = '';
				$this->role_members[$id] = array();
				$this->role_caps[$id] = array();
			}
			
			$delchkbx = ($new) ? '' : '<label for="delete_role_' . $id . '">' . __( 'Would you like this role to be deleted?', ESA_TEXT_DOMAIN ) . '</label> <input type="checkbox" value="on" name="delete_role[' . $id . ']" id="delete_role_' . $id . '"/>';
			
			$output = '
			<table class="form-table esa-options-table" id="esa-options-table-' . $id . '">';
			$output .= ( empty( $delchkbx ) ) ? '' : '
				<thead>
					<th>&nbsp;</th><td>' . $delchkbx . '</td>
				</thead>';
			$output .= '
				<tbody id="esa_options_' . $id . '">';
				$output .= $this->role_name_options( $id );
				$output .= $this->role_caps_options( $id );
				$output .= $this->role_members_options( $id );
				$output .= '
				</tbody>
			</table>';
			echo $output;
		}
		
		function role_name_options( $id=NULL ) {
			if( is_null( $id ) )
				return;
			
			$output = '
					<tr valign="top">
						<th scope="row">
							<label for="role_name_' . $id . '">' . __( 'Name of Role:', ESA_TEXT_DOMAIN ) . '</label>
						</th>';
			$output .= '
						<td>
							<input type="hidden" name="role_id[' . $id . ']" id="role_id_' . $id . '" value="' . $id . '"/>
							<input type="text" name="role_name[' . $id . ']" id="role_name_' . $id . '" value="' . ( ( array_key_exists( $id, $this->role_name ) ) ? $this->role_name[$id] : '' ) . '"/>
						</td>
					</tr>';
			return $output;
		}
		
		function role_caps_options( $id=NULL ) {
			if( is_null( $id ) )
				return;
			
			$allcaps = array_filter( array_keys( $this->get_allcaps() ), array( $this, 'remove_numeric_keys' ) );
			
			$output = '
					<tr valign="top">
						<th scope="row">
							' . __( 'Capabilities to Remove From This Role', ESA_TEXT_DOMAIN ) . '
						</th>
						<td>';
			foreach( $allcaps as $cap ) {
				$output .= '
							<div class="checkbox-container">';
				$output .= '
								<input type="checkbox" name="role_caps[' . $id . '][' . $cap . ']" id="role_caps_' . $id . '_' . $cap . '" value="on"' . checked( $this->role_caps[$id][$cap], 'on', false ) . '/>';
				$output .= '
								<label for="role_caps_' . $id . '_' . $cap . '">' . $cap . '</label>';
				if( !function_exists( 'findCap' ) )
					require_once( 'inc/retrieve-capabilities-info.php' );
				
				if( $caps_info = findCap( $cap, $this->caps_descriptions ) ) {
					$output .= '
								<div class="caps_info">';
					$this->caps_descriptions[$cap] = $caps_info;
					$output .= $this->caps_descriptions[$cap];
					$output .= '
								</div>';
				} else {
					$this->caps_descriptions[$cap] = false;
				}
				$output .= '
							</div>';
			}
			$output .= '
						</td>
					</tr>';
			
			return $output;
		}
		
		function role_members_options( $id=NULL ) {
			if( is_null( $id ) )
				return;
			
			if( !is_array( $this->role_members[$id] ) ) {
				$this->role_members[$id] = array( 0 => '' );
			}
			$output = '
					<tr valign="top">
						<th scope="row">
							<label for="role_members_' . $id . '">' . __( 'Users That Should Have This Role', ESA_TEXT_DOMAIN ) . '</label>
						</th>';
			$superadmins = $this->get_super_admin_list();
			$output .= '
						<td>
							<select name="role_members[' . $id . '][]" id="role_members_' . $id . '" multiple="multiple" class="role-members-select" size="10">';
			foreach( $superadmins as $admin ) {
				$sel = ( in_array( $admin, $this->role_members[$id] ) ) ? ' selected="selected"' : '';
				$output .= '
							<option value="' . $admin . '"' . $sel . '>' . $admin . '</option>';
			}
			$output .= '
							</select>
						</td>
					</tr>';
			
			return $output;
		}
		
		function get_super_admin_list() {
			return get_super_admins();
		}
		
		function get_allcaps() {
			if( !empty( $this->allcaps ) )
				return $this->allcaps;
			
			global $current_user;
			get_currentuserinfo();
			if( !empty( $current_user->allcaps ) && count( $current_user->allcaps ) > 1 )
				$this->allcaps = $current_user->allcaps;
			
			if( empty( $this->allcaps ) ) {
				$this->allcaps = array();
				$roles = new WP_Roles;
				$roles = $roles->roles;
				foreach( $roles as $name=>$role ) {
					$this->allcaps = array_merge( $role['capabilities'], $this->allcaps );
				}
			}
			$multisitecaps = array(
				'manage_network'			=> 1,
				'manage_network_plugins'	=> 1,
				'manage_network_users'		=> 1,
				'manage_network_themes'		=> 1,
				'manage_network_options'	=> 1,
				'manage_sites'				=> 1,
			);
			$this->allcaps = array_merge( $this->allcaps, $multisitecaps );
			
			ksort( $this->allcaps );
			
			return $this->allcaps;
		}
		
		function remove_numeric_keys($var) {
			return !is_numeric( $var );
		}
		
		function add_submenu_page() {
			global $wp_version;
			$options_page = ( version_compare( $wp_version, '3.0.9', '>' ) ) ? 'settings.php' : 'ms-admin.php';
			/* Add the new options page to the Super Admin menu */
			$rt = add_submenu_page( 
				/*$parent_slug = */$options_page, 
				/*$page_title = */'Extended Super Admin Settings', 
				/*$menu_title = */'Extended Super Admin', 
				/*$capability = */'manage_esa_options', 
				/*$menu_slug = */ESA_OPTIONS_PAGE, 
				/*$function = */array($this, 'admin_options_page')
			);
		}
	}
}
?>