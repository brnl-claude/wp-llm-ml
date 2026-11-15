<?php
/**
 * Classic widget wrapper around the language switcher.
 *
 * @package WPLLMML
 */

namespace WPLLMML;

defined( 'ABSPATH' ) || exit;

/**
 * Language switcher widget.
 */
class Switcher_Widget extends \WP_Widget {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			'wpllmml_switcher',
			__( 'Language switcher', 'wp-llm-ml' ),
			array(
				'description'           => __( 'Links to this page in the other languages.', 'wp-llm-ml' ),
				'show_instance_in_rest' => true,
			)
		);
	}

	/**
	 * Output.
	 *
	 * @param array $args     Sidebar args.
	 * @param array $instance Settings.
	 */
	public function widget( $args, $instance ) {
		$html = Plugin::instance()->switcher->render(
			array(
				'style' => isset( $instance['style'] ) ? $instance['style'] : 'list',
				'label' => isset( $instance['label'] ) ? $instance['label'] : Settings::get( 'switcher_label', 'name' ),
			)
		);
		if ( '' === $html ) {
			return;
		}
		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput
		if ( ! empty( $instance['title'] ) ) {
			echo $args['before_title'] . esc_html( apply_filters( 'widget_title', $instance['title'], $instance, $this->id_base ) ) . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput
		echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/**
	 * Settings form.
	 *
	 * @param array $instance Settings.
	 * @return void
	 */
	public function form( $instance ) {
		$title = isset( $instance['title'] ) ? $instance['title'] : '';
		$style = isset( $instance['style'] ) ? $instance['style'] : 'list';
		$label = isset( $instance['label'] ) ? $instance['label'] : 'name';
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Title:', 'wp-llm-ml' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>">
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'style' ) ); ?>"><?php esc_html_e( 'Layout:', 'wp-llm-ml' ); ?></label>
			<select id="<?php echo esc_attr( $this->get_field_id( 'style' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'style' ) ); ?>">
				<option value="list" <?php selected( $style, 'list' ); ?>><?php esc_html_e( 'List', 'wp-llm-ml' ); ?></option>
				<option value="inline" <?php selected( $style, 'inline' ); ?>><?php esc_html_e( 'Inline', 'wp-llm-ml' ); ?></option>
				<option value="dropdown" <?php selected( $style, 'dropdown' ); ?>><?php esc_html_e( 'Dropdown', 'wp-llm-ml' ); ?></option>
			</select>
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'label' ) ); ?>"><?php esc_html_e( 'Show:', 'wp-llm-ml' ); ?></label>
			<select id="<?php echo esc_attr( $this->get_field_id( 'label' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'label' ) ); ?>">
				<option value="name" <?php selected( $label, 'name' ); ?>><?php esc_html_e( 'Language name', 'wp-llm-ml' ); ?></option>
				<option value="code" <?php selected( $label, 'code' ); ?>><?php esc_html_e( 'Language code', 'wp-llm-ml' ); ?></option>
				<option value="both" <?php selected( $label, 'both' ); ?>><?php esc_html_e( 'Code and name', 'wp-llm-ml' ); ?></option>
			</select>
		</p>
		<?php
	}

	/**
	 * Sanitize settings.
	 *
	 * @param array $new_instance New.
	 * @param array $old_instance Old.
	 * @return array
	 */
	public function update( $new_instance, $old_instance ) {
		return array(
			'title' => sanitize_text_field( isset( $new_instance['title'] ) ? $new_instance['title'] : '' ),
			'style' => in_array( isset( $new_instance['style'] ) ? $new_instance['style'] : '', array( 'list', 'inline', 'dropdown' ), true ) ? $new_instance['style'] : 'list',
			'label' => in_array( isset( $new_instance['label'] ) ? $new_instance['label'] : '', array( 'name', 'code', 'both' ), true ) ? $new_instance['label'] : 'name',
		);
	}
}
