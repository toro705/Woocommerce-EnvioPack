<?php

namespace Ecomerciar\Enviopack\Utils;

use Ecomerciar\Enviopack\Enviopack;
use Ecomerciar\Enviopack\Helper;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

function add_method($methods)
{
	$methods['enviopack'] = 'Ecomerciar\Enviopack\WC_Enviopack';
	return $methods;
}

function add_maps()
{
	if (!wp_script_is('offices-map', $list = 'enqueued')) {
		wp_enqueue_script('offices-map', plugin_dir_url(__FILE__) . 'js/gmap.js', array('jquery'), 1.00001, true);
	}
	if (!wp_script_is('offices-map-init', $list = 'enqueued')) {
		wp_enqueue_script('offices-map-init', 'https://maps.googleapis.com/maps/api/js?key=' . get_option('enviopack_gmap_key'), array('jquery'), 1.00001, true);
	}
	wp_localize_script('offices-map', 'ajax_object', array('ajax_url' => admin_url('admin-ajax.php')));
	$chosen_shipping_method = WC()->session->get('chosen_shipping_methods');
	$chosen_shipping_method = $chosen_shipping_method[0];
	$chosen_shipping_method = explode(" ", $chosen_shipping_method);
	if ($chosen_shipping_method[0] === 'enviopack' && $chosen_shipping_method[1] === 'S') {
		echo '<h4>Seleccioná la sucursal donde querés recibir tu pedido</h4>';
		echo '<div style="height: 100%; margin-bottom:10px">';
		echo '<a id="ep-show-map" onclick="initMap()" class="button btn alt">Elegir sucursal</a>';
		echo '<div id="enviopack-map"></div>';
		echo '</div>';
	}
}

function get_offices()
{
	$cp = WC()->customer->get_shipping_postcode();
	if (empty($cp)) {
		$cp = WC()->customer->get_billing_postcode();
	}
	$province = WC()->customer->get_shipping_state();
	if (empty($province)) {
		$province = WC()->customer->get_billing_state();
	}
	$helper = new Helper;
	$ep = new Enviopack;
	$products = $helper->get_items_from_cart();
	$prices = $ep->get_price_to_office($province, $cp, $products['shipping_info']['total_weight'], $products['shipping_info']['products_details_1']);
	$center_coords = $helper->get_state_center($province);
	if (!$prices || !$center_coords) {
		return false;
	}
	wp_send_json_success(array('offices' => $prices, 'center_coords' => $center_coords));
}

function set_office()
{
	if (!isset(WC()->session)) {
		wp_die();
	}
	WC()->session->set('enviopack_office_address', preg_replace('/[^A-Za-z0-9\-\s]/', '', $_POST['office_address']));
	WC()->session->set('enviopack_office_service', filter_var($_POST['office_service'], FILTER_SANITIZE_STRING));
	WC()->session->set('enviopack_office_price', filter_var($_POST['office_price'], FILTER_VALIDATE_FLOAT));
	WC()->session->set('enviopack_office_id', filter_var($_POST['office_id'], FILTER_VALIDATE_INT));
	wp_send_json_success(array('office_id' => filter_var($_POST['office_id'], FILTER_VALIDATE_INT)));
}

function create_office_field($checkout)
{
	$id = WC()->session->get('enviopack_office_id');
	woocommerce_form_field('enviopack_office', array(
		'type' => 'text',
		'class' => array('form-row-first', 'hidden-field'),
		'label' => __('Sucursal Envío'),
		'default' => ($id ? $id : '-1'),
		'required' => true
	), $checkout->get_value('enviopack_office'));
}

function check_office_field()
{
	$chosen_shipping_method = WC()->session->get('chosen_shipping_methods');
	$chosen_shipping_method = $chosen_shipping_method[0];
	$chosen_shipping_method = explode(" ", $chosen_shipping_method);
	if ($chosen_shipping_method[0] === 'enviopack' && $chosen_shipping_method[1] === 'S' && $_POST['enviopack_office'] === -1) {
		wc_add_notice('Por favor elige una sucursal de envío', 'error');
	}
}

function update_order_meta($order_id)
{
	$chosen_shipping_method = WC()->session->get('chosen_shipping_methods');
	$chosen_shipping_method = $chosen_shipping_method[0];
	$chosen_shipping_method = explode(" ", $chosen_shipping_method);
	if ($chosen_shipping_method[0] === 'enviopack') {
		$data = array();
		$data['type'] = $chosen_shipping_method[1];
		$data['service'] = $chosen_shipping_method[2];
		$data['office'] = (isset($chosen_shipping_method[3]) ? $chosen_shipping_method[3] : '');
		$order = wc_get_order($order_id);
		$order->update_meta_data('enviopack_shipping_info', serialize($data));
		$order->save();
	}
}

function create_shipment($order_id)
{
	$order = wc_get_order($order_id);
	$ep = new Enviopack;
	$helper = new Helper($order);
	$customer = $helper->get_customer();
	$province_id = $helper->get_province_id();
	$zone_name = $helper->get_province_name($province_id);
	$shipment = $ep->create_shipment($order, $customer, $province_id, $zone_name);
	$order->update_meta_data('enviopack_shipment', serialize($shipment));
	$order->save();
}

function confirm_shipment($order_id, $courier_id = '', $source = 'auto')
{
	$order = wc_get_order($order_id);
	if (!$courier_id) {
		$courier_id = get_option('enviopack_shipping_mode');
	}
	// If order should send manually, but the source was from the auto send, cancel the execution
	if (($courier_id === 'manual' || !$courier_id) && $source === 'auto') {
		return false;
	}

	$shipping_method = unserialize($order->get_meta('enviopack_shipping_info', true));
	if (isset($shipping_method['type']) && $shipping_method['type'] === 'D' && !$courier_id) {
		wc_get_logger()->error('Enviopack -> Confirmando pedido - Pedido sin courier id', unserialize(LOGGER_CONTEXT));
		return false;
	}
	$ep = new Enviopack;
	if ($shipping_method['type'] === 'D') {
		$shipment = $ep->confirm_shipment($order, $courier_id);
	} else {
		$shipment = $ep->confirm_shipment($order);
	}
	$order->update_meta_data('enviopack_confirmed_shipment', serialize($shipment));
	$order->update_meta_data('enviopack_tracking_number', $shipment['id']);
	$label = $ep->get_label($order);
	$fp = fopen(plugin_dir_path(__FILE__) . 'labels/label-' . $order_id . '.pdf', 'wb');
	fwrite($fp, $label);
	fclose($fp);
	$order->save();
}

function add_box()
{
	add_meta_box(
		'enviopack_box',
		'EnvioPack',
		__NAMESPACE__ . '\box_content',
		'shop_order',
		'side'
	);
}

function box_content()
{
	global $post;

	$order = wc_get_order($post->ID);
	if (!empty($order->get_meta('enviopack_confirmed_shipment', true))) {
		$tracking_number = $order->get_meta('enviopack_tracking_number', true);
		if ($tracking_number) {
			echo 'Se ha generado y confirmado el envío de este pedido';
			echo '<br> Número de rastreo: <strong>' . $tracking_number . '</strong>';
			$store_url = get_page_link(get_page_by_title('Rastreo')->ID);
			if (strpos($store_url, '?') === false) {
				$store_url .= '?';
			} else {
				$store_url .= '&';
			}
			$store_url .= 'id=' . $tracking_number;
			echo '<br> <a href="' . $store_url . '" target="_blank">Rastrear pedido</a>';
			return;
		}
		echo 'Error al realizar el envío, por favor vuelve a intentarlo con otro correo';
	}
	$shipping_method = $order->get_shipping_methods();
	$shipping_method = array_shift($shipping_method);
	if ($shipping_method) {
		$shipping_method = explode(" ", $shipping_method->get_method_id());
		if ($shipping_method[0] === 'enviopack') {
			echo '<div style="margin: 20px 0">';
			$shipping_mode = get_option('enviopack_shipping_mode');
			if ($shipping_mode && $shipping_mode !== 'manual' && $order->get_status() !== 'completed') {
				echo 'Cuando el pedido esté completado, este se ingresará automáticamente al sistema usando el correo: ' . ucfirst(($shipping_mode));
				echo '</div>';
				return;
			}
			$shipping_method = unserialize($order->get_meta('enviopack_shipping_info', true));
			if (isset($shipping_method['type']) && $shipping_method['type'] === 'D') {
				$ep = new Enviopack;
				$couriers = $ep->get_prices_for_vendor($order);
				echo '<strong>Correo: </strong>';
				echo '<select style="width:80%" name="ep_courier">';
				foreach ($couriers as $courier) {
					echo '<option value="' . $courier['id'] . '">' . $courier['name'] . ' ' . $courier['service_name'] . ' - $' . $courier['price'] . '</option>';
				}
				echo '</select>';
			} else if (isset($shipping_method['type']) && $shipping_method['type'] === 'S') {
				echo '<strong>Correo: </strong> Ya seleccionado por la sucursal';
			}
			echo '<button class="button btn alt" style="display:block; width:100%; margin-top:20px; font-size:16px; height:40px;">Enviar</button>';
			echo '</div>';
		}
	}
}

function save_box($order_id)
{
	$order = wc_get_order($order_id);

	if (empty($_POST['woocommerce_meta_nonce'])) {
		return;
	}
	if (!current_user_can('edit_post', $order_id)) {
		return;
	}
	if (empty($_POST['post_ID']) || $_POST['post_ID'] != $order_id) {
		return;
	}
	if (!empty($order->get_meta('enviopack_confirmed_shipment', true)) && unserialize($order->get_meta('enviopack_confirmed_shipment', true))) {
		return;
	}

	if (!isset($_POST['ep_courier']) || empty($_POST['ep_courier'])) {
		$courier_id = '';
	} else {
		$courier_id = filter_var($_POST['ep_courier'], FILTER_SANITIZE_STRING);
	}

	confirm_shipment($order_id, $courier_id, 'manual');
}

function add_action_button($actions, $order)
{
	$shipping_method = $order->get_shipping_methods();
	$shipping_method = array_shift($shipping_method);
	if ($shipping_method) {
		$shipping_method = explode(" ", $shipping_method->get_method_id());
		$shipment_info = $order->get_meta('enviopack_confirmed_shipment', true);
		if ($shipping_method[0] === 'enviopack' && $shipment_info) {
			$actions['ep-label'] = array(
				'url' => plugin_dir_url(__FILE__) . 'labels/label-' . $order->get_id() . '.pdf',
				'name' => 'Ver etiqueta',
				'action' => 'ep-label',
			);
		}
	}
	return $actions;
}

function add_button_css_file($hook)
{
	if ($hook !== 'edit.php') {
		return;
	}
	wp_enqueue_style('action-button.css', plugin_dir_url(__FILE__) . 'css/action-button.css', array(), 1.0);
}

function create_page()
{
	global $wp_version;

	if (version_compare(PHP_VERSION, '5.6', '<')) {
		$flag = 'PHP';
		$version = '5.6';
	} else if (version_compare($wp_version, '4.9', '<')) {
		$flag = 'WordPress';
		$version = '4.9';
	} else {
		$content = '<h2>Número de envío</h2>
		<form method="get">
		<input type="text" name="id"style="width:40%"><br>
		<br />
		<input name="submit_button" type="submit"  value="Consultar"  id="update_button"  class="update_button"/>
		</form>
		[enviopack_tracking]';
		if (!post_exists('Rastreo', $content)) {
			wp_insert_post(array(
				'post_title' => 'Rastreo',
				'post_status' => 'publish',
				'post_type' => 'page',
				'post_content' => $content,
				'comment_status' => 'closed',
				'ping_status' => 'closed'
			));
		}
		return;
	}
	deactivate_plugins(basename(__FILE__));
	wp_die('<p><strong>Enviopack</strong> Requiere al menos ' . $flag . ' version ' . $version . ' o mayor.</p>', 'Plugin Activation Error', array('response' => 200, 'back_link' => true));
}

function destroy_page()
{
	$content = '<h2>Número de envío</h2>
	<form method="post">
	<input type="text" name="id"style="width:40%"><br>
	<br />
	<input name="submit_button" type="submit"  value="Consultar"  id="update_button"  class="update_button"/>
	</form>
	[enviopack_tracking]';
	$post_id = post_exists('Rastreo', $content);
	if ($post_id) {
		wp_delete_post($post_id, true);
	}
}

function create_shortcode()
{
	if (isset($_GET['id'])) {
		ob_start();
		$ep = new Enviopack;
		$ep_id = filter_var($_GET['id'], FILTER_SANITIZE_SPECIAL_CHARS);
		$tracking_statuses = $ep->get_tracking_statuses($ep_id);
		if (!empty($tracking_statuses)) {
			echo '<h3>Envío Nro: ' . $ep_id . '</h3>';
			echo "<table>";
			echo "<tr>";
			echo "<th width=\"30%\">Fecha</th>";
			echo "<th width=\"70%\">Estado actual</th>";
			echo "</tr>";
			foreach ($tracking_statuses as $tracking_status) {
				echo "<tr>";
				echo "<td>" . $tracking_status['fecha'] . "</td>";
				echo "<td>" . $tracking_status['mensaje'] . "</td>";
				echo "</tr>";
			}
			echo "</table>";
		} else {
			wc_print_notice('Hubo un error, por favor intenta nuevamente', 'error');
		}
		return ob_get_clean();
	}
}

function create_settings_link($links)
{
	$links[] = '<a href="' . esc_url(get_admin_url(null, 'options-general.php?page=enviopack_settings')) . '">Settings</a>';
	return $links;
}