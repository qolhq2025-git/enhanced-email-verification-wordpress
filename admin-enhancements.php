<?php
/**
 * Admin Enhancements for Email Verification
 * Adds custom columns, filters, and dashboard widgets for better user management
 *
 * @copyright dornaweb.com
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DWEmailVerifyAdminEnhancements extends DWEmailVerify{
	
	/**
	 * __construct
	 */
	public function __construct(){
		// Add custom column to users list
		add_filter( 'manage_users_columns', [ $this, 'add_verification_column' ] );
		add_filter( 'manage_users_custom_column', [ $this, 'show_verification_status' ], 10, 3 );
		add_filter( 'manage_users_sortable_columns', [ $this, 'make_verification_column_sortable' ] );
		
		// Add filter links
		add_filter( 'views_users', [ $this, 'add_verification_filter_links' ] );
		add_action( 'pre_get_users', [ $this, 'filter_users_by_verification' ] );
		
		// Add bulk actions
		add_filter( 'bulk_actions-users', [ $this, 'add_bulk_actions' ] );
		add_filter( 'handle_bulk_actions-users', [ $this, 'handle_bulk_actions' ], 10, 3 );
		add_action( 'admin_notices', [ $this, 'bulk_action_notices' ] );
		
		// Add quick action links
		add_filter( 'user_row_actions', [ $this, 'add_user_row_actions' ], 10, 2 );
		add_action( 'admin_init', [ $this, 'handle_quick_actions' ] );
		
		// Add dashboard widget
		add_action( 'wp_dashboard_setup', [ $this, 'add_dashboard_widget' ] );
		
		// Add custom CSS for styling
		add_action( 'admin_head', [ $this, 'admin_custom_css' ] );
	}
	
	/**
	 * Add verification status column
	 */
	public function add_verification_column( $columns ) {
		$columns['email_verification'] = __( 'Email Status', 'dwverify' );
		return $columns;
	}
	
	/**
	 * Show verification status in column
	 */
	public function show_verification_status( $value, $column_name, $user_id ) {
		if ( $column_name === 'email_verification' ) {
			$needs_verification = $this->needs_validation( $user_id );
			
			if ( $needs_verification === false ) {
				return '<span class="dw-verified-badge">✓ ' . __( 'Verified', 'dwverify' ) . '</span>';
			} else {
				$attempts = (int) get_user_meta( $user_id, 'verify-link-attempts', true );
				$max_attempts = (int) get_option( 'dw_verify_max_resend_allowed', 5 );
				
				if ( $attempts >= $max_attempts ) {
					return '<span class="dw-locked-badge">🔒 ' . __( 'Locked', 'dwverify' ) . '</span><br><small>' . sprintf( __( '%d attempts', 'dwverify' ), $attempts ) . '</small>';
				} else {
					return '<span class="dw-unverified-badge">✗ ' . __( 'Unverified', 'dwverify' ) . '</span><br><small>' . sprintf( __( '%d/%d attempts', 'dwverify' ), $attempts, $max_attempts ) . '</small>';
				}
			}
		}
		return $value;
	}
	
	/**
	 * Make verification column sortable
	 */
	public function make_verification_column_sortable( $columns ) {
		$columns['email_verification'] = 'email_verification';
		return $columns;
	}
	
	/**
	 * Add filter links at top of users list
	 */
	public function add_verification_filter_links( $views ) {
		// Count verified users
		$verified_count = $this->count_users_by_verification( true );
		$unverified_count = $this->count_users_by_verification( false );
		
		$current_filter = isset( $_GET['verification_status'] ) ? $_GET['verification_status'] : '';
		
		$views['verified'] = sprintf(
			'<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
			add_query_arg( 'verification_status', 'verified', admin_url( 'users.php' ) ),
			$current_filter === 'verified' ? 'current' : '',
			__( 'Verified', 'dwverify' ),
			$verified_count
		);
		
		$views['unverified'] = sprintf(
			'<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
			add_query_arg( 'verification_status', 'unverified', admin_url( 'users.php' ) ),
			$current_filter === 'unverified' ? 'current' : '',
			__( 'Unverified', 'dwverify' ),
			$unverified_count
		);
		
		return $views;
	}
	
	/**
	 * Filter users by verification status
	 */
	public function filter_users_by_verification( $query ) {
		global $pagenow;
		
		if ( $pagenow !== 'users.php' || ! is_admin() ) {
			return;
		}
		
		$verification_status = isset( $_GET['verification_status'] ) ? $_GET['verification_status'] : '';
		
		if ( $verification_status === 'verified' ) {
			$meta_query = [
				'relation' => 'OR',
				[
					'key'     => 'verify-lock',
					'value'   => self::UNLOCKED,
					'compare' => '='
				],
				[
					'key'     => 'verify-lock',
					'compare' => 'NOT EXISTS'
				]
			];
			$query->set( 'meta_query', $meta_query );
		} elseif ( $verification_status === 'unverified' ) {
			$query->set( 'meta_query', [
				[
					'key'     => 'verify-lock',
					'value'   => self::UNLOCKED,
					'compare' => '!='
				]
			] );
		}
	}
	
	/**
	 * Count users by verification status
	 */
	private function count_users_by_verification( $verified = true ) {
		global $wpdb;
		
		if ( $verified ) {
			// Count users who are verified (unlocked or no meta)
			$count = $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(DISTINCT u.ID)
				FROM {$wpdb->users} u
				LEFT JOIN {$wpdb->usermeta} um ON u.ID = um.user_id AND um.meta_key = 'verify-lock'
				WHERE um.meta_value IS NULL OR um.meta_value = %s",
				self::UNLOCKED
			) );
		} else {
			// Count users who are unverified (locked)
			$count = $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(DISTINCT u.ID)
				FROM {$wpdb->users} u
				INNER JOIN {$wpdb->usermeta} um ON u.ID = um.user_id
				WHERE um.meta_key = 'verify-lock' AND um.meta_value != %s",
				self::UNLOCKED
			) );
		}
		
		return (int) $count;
	}
	
	/**
	 * Add bulk actions
	 */
	public function add_bulk_actions( $actions ) {
		$actions['verify_unlock'] = __( 'Unlock (Verify Email)', 'dwverify' );
		$actions['verify_resend'] = __( 'Resend Verification Email', 'dwverify' );
		return $actions;
	}
	
	/**
	 * Handle bulk actions
	 */
	public function handle_bulk_actions( $redirect_to, $action, $user_ids ) {
		if ( ! in_array( $action, [ 'verify_unlock', 'verify_resend' ] ) ) {
			return $redirect_to;
		}
		
		if ( ! current_user_can( 'edit_users' ) ) {
			return $redirect_to;
		}
		
		$count = 0;
		
		foreach ( $user_ids as $user_id ) {
			if ( $action === 'verify_unlock' ) {
				$this->unlock_user( $user_id );
				$count++;
			} elseif ( $action === 'verify_resend' ) {
				$this->send_verification_link( $user_id );
				$count++;
			}
		}
		
		$redirect_to = add_query_arg( 'bulk_verify_action', $action, $redirect_to );
		$redirect_to = add_query_arg( 'bulk_verify_count', $count, $redirect_to );
		
		return $redirect_to;
	}
	
	/**
	 * Show admin notices after bulk actions
	 */
	public function bulk_action_notices() {
		// Bulk action notices
		if ( isset( $_GET['bulk_verify_action'] ) && isset( $_GET['bulk_verify_count'] ) ) {
			$action = $_GET['bulk_verify_action'];
			$count = (int) $_GET['bulk_verify_count'];
			
			if ( $action === 'verify_unlock' ) {
				printf(
					'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
					sprintf(
						_n(
							'%d user unlocked successfully.',
							'%d users unlocked successfully.',
							$count,
							'dwverify'
						),
						$count
					)
				);
			} elseif ( $action === 'verify_resend' ) {
				printf(
					'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
					sprintf(
						_n(
							'Verification email sent to %d user.',
							'Verification emails sent to %d users.',
							$count,
							'dwverify'
						),
						$count
					)
				);
			}
		}
		
		// Quick action notices
		if ( isset( $_GET['verify_action'] ) && isset( $_GET['verify_user_id'] ) ) {
			$action = $_GET['verify_action'];
			$user_id = (int) $_GET['verify_user_id'];
			$user = get_user_by( 'id', $user_id );
			$username = $user ? $user->user_login : __( 'User', 'dwverify' );
			
			if ( $action === 'unlocked' ) {
				printf(
					'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
					sprintf(
						__( 'User %s has been unlocked successfully.', 'dwverify' ),
						'<strong>' . esc_html( $username ) . '</strong>'
					)
				);
			} elseif ( $action === 'resent' ) {
				printf(
					'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
					sprintf(
						__( 'Verification email sent to %s.', 'dwverify' ),
						'<strong>' . esc_html( $username ) . '</strong>'
					)
				);
			}
		}
	}
	
	/**
	 * Add quick action links to user row
	 */
	public function add_user_row_actions( $actions, $user ) {
		if ( ! current_user_can( 'edit_users' ) ) {
			return $actions;
		}
		
		$needs_verification = $this->needs_validation( $user->ID );
		
		if ( $needs_verification !== false ) {
			// User is unverified - add unlock action
			$actions['verify_unlock'] = sprintf(
				'<a href="%s" style="color: #00a32a;">%s</a>',
				wp_nonce_url(
					add_query_arg( [
						'action' => 'verify_unlock_user',
						'user_id' => $user->ID
					], admin_url( 'users.php' ) ),
					'verify_unlock_' . $user->ID
				),
				__( 'Unlock User', 'dwverify' )
			);
			
			$actions['verify_resend'] = sprintf(
				'<a href="%s" style="color: #2271b1;">%s</a>',
				wp_nonce_url(
					add_query_arg( [
						'action' => 'verify_resend_email',
						'user_id' => $user->ID
					], admin_url( 'users.php' ) ),
					'verify_resend_' . $user->ID
				),
				__( 'Resend Email', 'dwverify' )
			);
		}
		
		return $actions;
	}
	
	/**
	 * Handle quick actions from user row links
	 */
	public function handle_quick_actions() {
		if ( ! isset( $_GET['action'] ) || ! isset( $_GET['user_id'] ) ) {
			return;
		}
		
		if ( ! current_user_can( 'edit_users' ) ) {
			return;
		}
		
		$action = $_GET['action'];
		$user_id = (int) $_GET['user_id'];
		
		if ( $action === 'verify_unlock_user' ) {
			check_admin_referer( 'verify_unlock_' . $user_id );
			$this->unlock_user( $user_id );
			wp_redirect( add_query_arg( [
				'verify_action' => 'unlocked',
				'verify_user_id' => $user_id
			], admin_url( 'users.php' ) ) );
			exit;
		} elseif ( $action === 'verify_resend_email' ) {
			check_admin_referer( 'verify_resend_' . $user_id );
			$this->send_verification_link( $user_id );
			wp_redirect( add_query_arg( [
				'verify_action' => 'resent',
				'verify_user_id' => $user_id
			], admin_url( 'users.php' ) ) );
			exit;
		}
	}
	
	/**
	 * Add dashboard widget
	 */
	public function add_dashboard_widget() {
		wp_add_dashboard_widget(
			'dw_email_verification_stats',
			__( 'Email Verification Stats', 'dwverify' ),
			[ $this, 'dashboard_widget_content' ]
		);
	}
	
	/**
	 * Dashboard widget content
	 */
	public function dashboard_widget_content() {
		$verified_count = $this->count_users_by_verification( true );
		$unverified_count = $this->count_users_by_verification( false );
		$total_users = $verified_count + $unverified_count;
		
		// Get locked users (exceeded resend attempts)
		global $wpdb;
		$max_attempts = (int) get_option( 'dw_verify_max_resend_allowed', 5 );
		$locked_count = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(DISTINCT u.ID)
			FROM {$wpdb->users} u
			INNER JOIN {$wpdb->usermeta} um1 ON u.ID = um1.user_id
			INNER JOIN {$wpdb->usermeta} um2 ON u.ID = um2.user_id
			WHERE um1.meta_key = 'verify-lock' 
			AND um1.meta_value != %s
			AND um2.meta_key = 'verify-link-attempts'
			AND CAST(um2.meta_value AS UNSIGNED) >= %d",
			self::UNLOCKED,
			$max_attempts
		) );
		
		$verification_rate = $total_users > 0 ? round( ( $verified_count / $total_users ) * 100, 1 ) : 0;
		
		?>
		<div class="dw-verification-stats">
			<div class="dw-stat-box">
				<div class="dw-stat-number"><?php echo esc_html( $total_users ); ?></div>
				<div class="dw-stat-label"><?php _e( 'Total Users', 'dwverify' ); ?></div>
			</div>
			
			<div class="dw-stat-box dw-stat-success">
				<div class="dw-stat-number"><?php echo esc_html( $verified_count ); ?></div>
				<div class="dw-stat-label"><?php _e( 'Verified', 'dwverify' ); ?></div>
			</div>
			
			<div class="dw-stat-box dw-stat-warning">
				<div class="dw-stat-number"><?php echo esc_html( $unverified_count ); ?></div>
				<div class="dw-stat-label"><?php _e( 'Unverified', 'dwverify' ); ?></div>
			</div>
			
			<?php if ( $locked_count > 0 ) : ?>
			<div class="dw-stat-box dw-stat-danger">
				<div class="dw-stat-number"><?php echo esc_html( $locked_count ); ?></div>
				<div class="dw-stat-label"><?php _e( 'Locked', 'dwverify' ); ?></div>
			</div>
			<?php endif; ?>
			
			<div class="dw-stat-box dw-stat-info">
				<div class="dw-stat-number"><?php echo esc_html( $verification_rate ); ?>%</div>
				<div class="dw-stat-label"><?php _e( 'Verification Rate', 'dwverify' ); ?></div>
			</div>
		</div>
		
		<div class="dw-widget-actions">
			<a href="<?php echo esc_url( admin_url( 'users.php?verification_status=unverified' ) ); ?>" class="button button-primary">
				<?php _e( 'View Unverified Users', 'dwverify' ); ?>
			</a>
			<a href="<?php echo esc_url( admin_url( 'options-general.php?page=dw-email-verifications.php' ) ); ?>" class="button">
				<?php _e( 'Settings', 'dwverify' ); ?>
			</a>
		</div>
		<?php
	}
	
	/**
	 * Add custom CSS for admin styling
	 */
	public function admin_custom_css() {
		$screen = get_current_screen();
		if ( ! $screen || ( $screen->id !== 'users' && $screen->id !== 'dashboard' ) ) {
			return;
		}
		?>
		<style>
			/* Verification status badges */
			.dw-verified-badge {
				display: inline-block;
				padding: 3px 8px;
				border-radius: 3px;
				background-color: #00a32a;
				color: white;
				font-size: 12px;
				font-weight: 600;
			}
			
			.dw-unverified-badge {
				display: inline-block;
				padding: 3px 8px;
				border-radius: 3px;
				background-color: #f0b849;
				color: white;
				font-size: 12px;
				font-weight: 600;
			}
			
			.dw-locked-badge {
				display: inline-block;
				padding: 3px 8px;
				border-radius: 3px;
				background-color: #d63638;
				color: white;
				font-size: 12px;
				font-weight: 600;
			}
			
			/* Dashboard widget styling */
			.dw-verification-stats {
				display: grid;
				grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
				gap: 15px;
				margin-bottom: 20px;
			}
			
			.dw-stat-box {
				text-align: center;
				padding: 15px;
				background: #f0f0f1;
				border-radius: 5px;
				border-left: 4px solid #2271b1;
			}
			
			.dw-stat-box.dw-stat-success {
				border-left-color: #00a32a;
			}
			
			.dw-stat-box.dw-stat-warning {
				border-left-color: #f0b849;
			}
			
			.dw-stat-box.dw-stat-danger {
				border-left-color: #d63638;
			}
			
			.dw-stat-box.dw-stat-info {
				border-left-color: #2271b1;
			}
			
			.dw-stat-number {
				font-size: 32px;
				font-weight: 700;
				line-height: 1;
				margin-bottom: 5px;
			}
			
			.dw-stat-label {
				font-size: 13px;
				color: #646970;
			}
			
			.dw-widget-actions {
				display: flex;
				gap: 10px;
			}
			
			.dw-widget-actions .button {
				flex: 1;
			}
			
			/* User table column width */
			.column-email_verification {
				width: 140px;
			}
		</style>
		<?php
	}
}

new DWEmailVerifyAdminEnhancements();
