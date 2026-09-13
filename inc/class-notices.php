<?php
declare(strict_types = 1);

namespace epiphyt\Plugin_Updater;

/**
 * Admin notices and plugin row output.
 * 
 * The only class in this package producing output, and therefore the only one
 * performing escaping.
 * 
 * @author	Epiphyt
 * @license	GPL2
 * @package	epiphyt\Plugin_Updater
 */
final class Notices {
	/**
	 * @var		bool Whether the row style has already been printed
	 */
	private bool $printed_style = false;
	
	/**
	 * @var		\epiphyt\Plugin_Updater\Config Plugin configuration
	 */
	private Config $config;
	
	/**
	 * @var		\epiphyt\Plugin_Updater\License License handler
	 */
	private License $license;
	
	/**
	 * Class constructor.
	 * 
	 * @param	\epiphyt\Plugin_Updater\Config	$config Plugin configuration
	 * @param	\epiphyt\Plugin_Updater\License	$license License handler
	 */
	public function __construct( Config $config, License $license ) {
		$this->config = $config;
		$this->license = $license;
	}
	
	/**
	 * Output a notice if the license activation failed.
	 */
	public function activation_failed_notice(): void {
		if ( ! $this->is_relevant_screen() || ! $this->license->has_failed() ) {
			return;
		}
		
		$this->render_notice(
			\sprintf(
				$this->config->get_string( Strings::ACTIVATION_FAILED_NOTICE ),
				$this->config->get_product_name()
			),
			$this->get_error_message( $this->license->get_response() )
		);
	}
	
	/**
	 * Output a plugin row if the license activation failed.
	 */
	public function activation_failed_row(): void {
		if ( ! $this->license->has_failed() ) {
			return;
		}
		
		$message = \sprintf(
			$this->config->get_string( Strings::ACTIVATION_FAILED_ROW ),
			$this->config->get_product_name()
		) . ' ' . \sprintf(
			$this->config->get_string( Strings::ACTIVATION_FAILED_ROW_HINT ),
			$this->config->get_product_name()
		);
		
		$this->render_row( \esc_html( $message ) . '<br>' . $this->get_settings_link() );
	}
	
	/**
	 * Output a notice if the license deactivation failed.
	 */
	public function deactivation_failed_notice(): void {
		if ( ! $this->is_relevant_screen() || ! $this->license->has_failed_deactivation() ) {
			return;
		}
		
		$this->render_notice(
			\sprintf(
				$this->config->get_string( Strings::DEACTIVATION_FAILED_NOTICE ),
				$this->config->get_product_name()
			),
			$this->get_error_message( $this->license->get_deactivation_response() )
		);
	}
	
	/**
	 * Output a plugin row if the license has expired.
	 */
	public function expired_row(): void {
		if ( ! $this->license->is_expired() ) {
			return;
		}
		
		$this->render_row( $this->get_expired_notice() );
	}
	
	/**
	 * Get the number of columns of the plugin list table.
	 * 
	 * @return	int Number of columns
	 */
	private function get_column_count(): int {
		$list_table = $GLOBALS['wp_list_table'] ?? null;
		
		if ( \is_object( $list_table ) && \method_exists( $list_table, 'get_column_count' ) ) {
			$count = $list_table->get_column_count();
			
			if ( \is_int( $count ) && $count > 0 ) {
				return $count;
			}
		}
		
		return 4;
	}
	
	/**
	 * Get the error message of a response.
	 * 
	 * @param	array<string, mixed>	$response Stored response
	 * @return	string Error message
	 */
	private function get_error_message( array $response ): string {
		$error = $response['error'] ?? null;
		
		if ( \is_string( $error ) && $error !== '' ) {
			return $error;
		}
		
		return $this->config->get_string( Strings::UNKNOWN_ERROR );
	}
	
	/**
	 * Get the notice shown when the license has expired.
	 * 
	 * @return	string Escaped notice markup
	 */
	public function get_expired_notice(): string {
		$link = \sprintf(
			'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
			\esc_url( $this->config->get_string( Strings::RENEWAL_URL ) ),
			\esc_html( $this->config->get_string( Strings::RENEWAL_LINK_TEXT ) )
		);
		
		return \wp_kses_post( \sprintf(
			$this->config->get_string( Strings::EXPIRED_NOTICE ),
			$link
		) );
	}
	
	/**
	 * Get the link to the license settings.
	 * 
	 * @return	string Escaped link markup, or an empty string
	 */
	private function get_settings_link(): string {
		$url = $this->config->get_settings_url();
		
		if ( $url === '' ) {
			return '';
		}
		
		return \sprintf(
			'<a href="%1$s">%2$s</a>',
			\esc_url( $url ),
			\esc_html( $this->config->get_string( Strings::EDIT_CREDENTIALS ) )
		);
	}
	
	/**
	 * Check whether notices should be shown on the current screen.
	 * 
	 * @return	bool Whether the current screen is relevant
	 */
	private function is_relevant_screen(): bool {
		if ( ! \current_user_can( 'update_plugins' ) ) {
			return false;
		}
		
		$pagenow = $GLOBALS['pagenow'] ?? '';
		
		if ( $pagenow === 'plugins.php' ) {
			return true;
		}
		
		$page_slug = $this->config->get_settings_page_slug();
		
		if ( $page_slug === '' ) {
			return false;
		}
		
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return isset( $_GET['page'] ) && $_GET['page'] === $page_slug;
	}
	
	/**
	 * Register all hooks.
	 */
	public function register_hooks(): void {
		$basename = $this->config->get_plugin_basename();
		
		\add_action( 'admin_notices', [ $this, 'activation_failed_notice' ], 100 );
		\add_action( 'admin_notices', [ $this, 'deactivation_failed_notice' ], 100 );
		\add_action( 'network_admin_notices', [ $this, 'activation_failed_notice' ], 100 );
		\add_action( 'network_admin_notices', [ $this, 'deactivation_failed_notice' ], 100 );
		\add_action( 'after_plugin_row_' . $basename, [ $this, 'activation_failed_row' ], 20 );
		\add_action( 'after_plugin_row_' . $basename, [ $this, 'expired_row' ], 20 );
	}
	
	/**
	 * Output an admin notice.
	 * 
	 * @param	string	$title Notice title
	 * @param	string	$message Notice message
	 */
	private function render_notice( string $title, string $message ): void {
		$link = $this->get_settings_link();
		
		\wp_admin_notice(
			\sprintf(
				'<strong>%1$s</strong><br>%2$s%3$s',
				\esc_html( $title ),
				\esc_html( $message ),
				$link !== '' ? '<br>' . \wp_kses_post( $link ) : ''
			),
			[
				'type' => 'error',
			]
		);
	}
	
	/**
	 * Output a plugin row.
	 * 
	 * @param	string	$content Escaped row content
	 */
	private function render_row( string $content ): void {
		$basename = $this->config->get_plugin_basename();
		?>
		<tr class="plugin-update-tr active">
			<td colspan="<?php echo \esc_attr( (string) $this->get_column_count() ); ?>" class="plugin-update">
				<?php
				\wp_admin_notice(
					\wp_kses_post( $content ),
					[
						'additional_classes' => [
							'inline',
							'notice-alt',
							'update-message',
						],
						'type' => 'error',
					]
				);
				?>
			</td>
		</tr>
		<?php
		if ( $this->printed_style ) {
			return;
		}
		
		$this->printed_style = true;
		?>
		<style>
			.plugins [data-plugin="<?php echo \esc_attr( $basename ); ?>"] th,
			.plugins [data-plugin="<?php echo \esc_attr( $basename ); ?>"] td {
				box-shadow: none;
			}
		</style>
		<?php
	}
}
