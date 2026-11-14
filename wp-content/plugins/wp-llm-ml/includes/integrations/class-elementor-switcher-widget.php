<?php
/**
 * Elementor widget: language switcher.
 *
 * @package WPLLMML
 */

namespace WPLLMML\Integrations;

use WPLLMML\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Language switcher widget for Elementor.
 */
class Elementor_Switcher_Widget extends \Elementor\Widget_Base {

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'wpllmml-switcher';
	}

	/**
	 * Title.
	 *
	 * @return string
	 */
	public function get_title() {
		return __( 'Language switcher', 'wp-llm-ml' );
	}

	/**
	 * Icon.
	 *
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-globe';
	}

	/**
	 * Categories.
	 *
	 * @return string[]
	 */
	public function get_categories() {
		return array( 'basic' );
	}

	/**
	 * Search keywords.
	 *
	 * @return string[]
	 */
	public function get_keywords() {
		return array( 'language', 'translation', 'multilingual', 'taal', 'switcher' );
	}

	/**
	 * Controls.
	 */
	protected function register_controls() {
		$this->start_controls_section(
			'content',
			array( 'label' => __( 'Language switcher', 'wp-llm-ml' ) )
		);
		$this->add_control(
			'style',
			array(
				'label'   => __( 'Layout', 'wp-llm-ml' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => 'inline',
				'options' => array(
					'inline'   => __( 'Inline', 'wp-llm-ml' ),
					'list'     => __( 'List', 'wp-llm-ml' ),
					'dropdown' => __( 'Dropdown', 'wp-llm-ml' ),
				),
			)
		);
		$this->add_control(
			'label',
			array(
				'label'   => __( 'Show', 'wp-llm-ml' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => 'name',
				'options' => array(
					'name' => __( 'Language name', 'wp-llm-ml' ),
					'code' => __( 'Language code', 'wp-llm-ml' ),
					'both' => __( 'Code and name', 'wp-llm-ml' ),
				),
			)
		);
		$this->add_control(
			'show_current',
			array(
				'label'        => __( 'Show current language', 'wp-llm-ml' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
			)
		);
		$this->add_responsive_control(
			'align',
			array(
				'label'     => __( 'Alignment', 'wp-llm-ml' ),
				'type'      => \Elementor\Controls_Manager::CHOOSE,
				'options'   => array(
					'flex-start' => array(
						'title' => __( 'Left', 'wp-llm-ml' ),
						'icon'  => 'eicon-text-align-left',
					),
					'center'     => array(
						'title' => __( 'Center', 'wp-llm-ml' ),
						'icon'  => 'eicon-text-align-center',
					),
					'flex-end'   => array(
						'title' => __( 'Right', 'wp-llm-ml' ),
						'icon'  => 'eicon-text-align-right',
					),
				),
				'selectors' => array(
					'{{WRAPPER}} .wpllmml-switcher__list' => 'justify-content: {{VALUE}};',
				),
			)
		);
		$this->end_controls_section();

		$this->start_controls_section(
			'style_section',
			array(
				'label' => __( 'Style', 'wp-llm-ml' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);
		$this->add_control(
			'color',
			array(
				'label'     => __( 'Text color', 'wp-llm-ml' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}} .wpllmml-switcher a, {{WRAPPER}} .wpllmml-switcher summary' => 'color: {{VALUE}};' ),
			)
		);
		$this->add_control(
			'current_color',
			array(
				'label'     => __( 'Current language color', 'wp-llm-ml' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}} .wpllmml-switcher .is-current a' => 'color: {{VALUE}};' ),
			)
		);
		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'typography',
				'selector' => '{{WRAPPER}} .wpllmml-switcher',
			)
		);
		$this->end_controls_section();
	}

	/**
	 * Output.
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();
		echo Plugin::instance()->switcher->render( // phpcs:ignore WordPress.Security.EscapeOutput
			array(
				'style'        => $settings['style'],
				'label'        => $settings['label'],
				'show_current' => 'yes' === $settings['show_current'],
			)
		);
	}
}
