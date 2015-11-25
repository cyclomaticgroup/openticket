<?php ( __FILE__ == $_SERVER['SCRIPT_FILENAME'] ) ? die( header( 'Location: /' ) ) : null;

// controls the core functionality of the evet area post type
class QSOT_Post_Type_Event_Area {
	// container for the singleton instance
	protected static $instance = null;

	// get the singleton instance
	public static function instance() {
		// if the instance already exists, use it
		if ( isset( self::$instance ) && self::$instance instanceof QSOT_Post_Type_Event_Area )
			return self::$instance;

		// otherwise, start a new instance
		return self::$instance = new QSOT_Post_Type_Event_Area();
	}

	// constructor. handles instance setup, and multi instance prevention
	public function __construct() {
		// if there is already an instance of this object, then bail now
		if ( isset( self::$instance ) && self::$instance instanceof QSOT_Post_Type_Event_Area )
			throw new Exception( sprintf( __( 'There can only be one instance of the %s object at a time.', 'opentickets-community-edition' ), __CLASS__ ), 12000 );

		// otherwise, set this as the known instance
		self::$instance = $this;

		// and call the intialization function
		$this->initialize();
	}

	// destructor. handles instance destruction
	public function __destruct() {
		$this->deinitialize();
	}


	// container for all the registered event area types, ordered by priority
	protected $area_types = array();
	protected $find_order = array();

	// initialize the object. maybe add actions and filters
	public function initialize() {
		$this->_setup_admin_options();
		// setup the tables and table names used by the event area section
		$this->setup_table_names();
		add_action( 'switch_blog', array( &$this, 'setup_table_names' ), PHP_INT_MAX, 2 );
		add_filter( 'qsot-upgrader-table-descriptions', array( &$this, 'setup_tables' ), 1 );

		// action to register the post type
		add_action( 'init', array( &$this, 'register_post_type' ), 2 );
		
		// register the assets we need for this post type
		add_action( 'init', array( &$this, 'register_assets' ), 1000 );

		// during save post action, run the appropriate area_type save functionality
		add_action( 'save_post', array( &$this, 'save_post' ), 1000, 3 );

		// area type registration and deregistration
		add_action( 'qsot-register-event-area-type', array( &$this, 'register_event_area_type' ), 1000, 1 );
		add_action( 'qsot-deregister-event-area-type', array( &$this, 'deregister_event_area_type' ), 1000, 1 );

		// add the generic event area type metabox
		add_action( 'add_meta_boxes_qsot-event-area', array( &$this, 'add_meta_boxes' ), 1000 );

		// enqueue our needed admin assets
		add_action( 'qsot-admin-load-assets-qsot-event-area', array( &$this, 'enqueue_admin_assets_event_area' ), 10, 2 );
		add_action( 'qsot-admin-load-assets-qsot-event', array( &$this, 'enqueue_admin_assets_event' ), 10, 2 );

		// enqueue the frontend assets
		add_action( 'qsot-frontend-event-assets', array( &$this, 'enqueue_assets' ), 10 );

		// get the event area
		add_filter( 'qsot-event-area-for-event', array( &$this, 'get_event_area_for_event' ), 10, 2 );
		add_filter( 'qsot-event-area-type-for-event', array( &$this, 'get_event_area_type_for_event' ), 10, 2 );
		add_filter( 'qsot-get-event-area', array( &$this, 'get_event_area' ), 10, 2 );

		// add the event ticket selection UI to the output of the event
		add_filter( 'qsot-event-the-content', array( &$this, 'draw_event_area' ), 1000, 2 );

		// draw the event area image
		add_action( 'qsot-draw-event-area-image', array( &$this, 'draw_event_area_image' ), 10, 4 );

		// handle the display and storage of all order/cart item meta data
		add_filter( 'woocommerce_get_cart_item_from_session', array( &$this, 'load_item_data' ), 20, 3 );
		add_action( 'woocommerce_add_order_item_meta', array( &$this, 'add_item_meta' ), 10, 3 );
		add_action( 'woocommerce_ajax_add_order_item_meta', array( &$this, 'add_item_meta' ), 10, 3 );
		add_filter( 'woocommerce_hidden_order_itemmeta', array( &$this, 'hide_item_meta' ), 10, 1 );
		add_action( 'woocommerce_before_view_order_itemmeta', array( &$this, 'before_view_item_meta' ), 10, 3 );
		add_action( 'woocommerce_before_edit_order_itemmeta', array( &$this, 'before_edit_item_meta' ), 10, 3 );

		// when saving the list of order items, during the editing of the list in the edit order page, we need to possibly update our reservation table
		add_action( 'woocommerce_saved_order_items', array( &$this, 'save_order_items' ), 10, 2 );

		// handle syncing of cart items to the values in the ticket table
		add_action( 'wp_loaded', array( &$this, 'sync_cart_tickets' ), 6 );
		add_action( 'woocommerce_cart_loaded_from_session', array( &$this, 'sync_cart_tickets' ), 6 );
		add_action( 'qsot-sync-cart', array( &$this, 'sync_cart_tickets' ), 10 );
		add_action( 'qsot-clear-zone-locks', array( &$this, 'clear_zone_locks' ), 10, 1 );

		// during transitions of order status, we need to perform certain operations. we may need to confirm tickets, or cancel them, depending on the transition
		add_action( 'woocommerce_order_status_changed', array( &$this, 'order_status_changed' ), 100, 3 );
		add_action( 'woocommerce_order_status_changed', array( &$this, 'order_status_changed_pending' ), 101, 3 );
		add_action( 'woocommerce_order_status_changed', array( &$this, 'order_status_changed_cancel' ), 102, 3 );

		// solve order again conundrum
		add_filter( 'woocommerce_order_again_cart_item_data', array( &$this, 'adjust_order_again_items' ), 10, 3 );
		add_filter( 'woocommerce_add_to_cart_validation', array( &$this, 'sniff_order_again_and_readd_to_cart' ), 10, 6 );

		// sub event bulk edit stuff
		add_action( 'qsot-events-bulk-edit-settings', array( &$this, 'event_area_bulk_edit_settings' ), 30, 2 );
		add_filter( 'qsot-events-save-sub-event-settings', array( &$this, 'save_sub_event_settings' ), 10, 3 );
		add_filter( 'qsot-load-child-event-settings', array( &$this, 'load_child_event_settings' ), 10, 3 );

		// upon order item removal, we need to deregister ticket reservations
		add_action( 'woocommerce_before_delete_order_item', array( &$this, 'woocommerce_before_delete_order_item' ), 10, 1 );

		// load the information needed to display the ticket
		add_filter( 'qsot-compile-ticket-info', array( &$this, 'add_event_area_data' ), 2000, 3 );

		// when in the admin, add some more actions and filters
		if ( is_admin() ) {
			// admin order editing
			add_action( 'qsot-admin-load-assets-shop_order', array( &$this, 'load_assets_edit_order' ), 10, 2 );
			add_filter( 'qsot-ticket-selection-templates', array( &$this, 'admin_ticket_selection_templates' ), 10, 3 );

			// admin add ticket button
			add_action( 'woocommerce_order_item_add_line_buttons', array( &$this, 'add_tickets_button' ), 10, 3 );
		}

		// add the generic admin ajax handlers
		$aj = QSOT_Ajax::instance();
		$aj->register( 'load-event', array( &$this, 'admin_ajax_load_event' ), array( 'edit_shop_orders' ), null, 'qsot-admin-ajax' );
	}

	// deinitialize the object. remove actions and filter
	public function deinitialize() {
		remove_action( 'switch_blog', array( &$this, 'setup_table_names' ), PHP_INT_MAX );
		remove_filter( 'qsot-upgrader-table-descriptions', array( &$this, 'setup_tables' ) );
		remove_action( 'init', array( &$this, 'register_post_type' ), 2 );
		remove_action( 'init', array( &$this, 'register_assets' ), 1000 );
		remove_action( 'qsot-register-event-area-type', array( &$this, 'register_event_area_type' ), 1000 );
		remove_action( 'qsot-deregister-event-area-type', array( &$this, 'deregister_event_area_type' ), 1000 );
		remove_action( 'add_meta_boxes_qsot-event-area', array( &$this, 'add_meta_boxes' ), 1000 );
		remove_action( 'qsot-admin-load-assets-qsot-event-area', array( &$this, 'enqueue_admin_assets_event_area' ), 10 );
		remove_action( 'qsot-admin-load-assets-qsot-event', array( &$this, 'enqueue_admin_assets_event' ), 10 );
		remove_filter( 'qsot-event-area-for-event', array( &$this, 'get_event_area_for_event' ), 10 );
		remove_filter( 'qsot-event-area-type-for-event', array( &$this, 'get_event_area_type_for_event' ), 10 );
		remove_filter( 'qsot-get-event-area', array( &$this, 'get_event_area' ), 10 );
		remove_filter( 'woocommerce_get_cart_item_from_session', array( &$this, 'load_item_data' ), 20 );
		remove_action( 'woocommerce_add_order_item_meta', array( &$this, 'add_item_meta' ), 10 );
		remove_action( 'woocommerce_ajax_add_order_item_meta', array( &$this, 'add_item_meta' ), 10 );
		remove_filter( 'woocommerce_hidden_order_itemmeta', array( &$this, 'hide_item_meta' ), 10 );
		remove_action( 'woocommerce_before_view_order_itemmeta', array( &$this, 'before_view_item_meta' ), 10 );
		remove_action( 'woocommerce_before_edit_order_itemmeta', array( &$this, 'before_edit_item_meta' ), 10 );
		remove_action( 'wp_loaded', array( &$this, 'sync_cart_tickets' ), 6 );
		remove_action( 'woocommerce_cart_loaded_from_session', array( &$this, 'sync_cart_tickets' ), 6 );
		remove_action( 'qsot-sync-cart', array( &$this, 'sync_cart_tickets' ), 10 );
		remove_action( 'qsot-clear-zone-locks', array( &$this, 'clear_zone_locks' ), 10 );
		remove_action( 'woocommerce_order_status_changed', array( &$this, 'order_status_changed' ), 100 );
		remove_action( 'woocommerce_order_status_changed', array( &$this, 'order_status_changed_pending' ), 101 );
		remove_action( 'woocommerce_order_status_changed', array( &$this, 'order_status_changed_cancel' ), 102 );
		remove_filter( 'woocommerce_order_again_cart_item_data', array( &$this, 'adjust_order_again_items' ), 10 );
		remove_filter( 'woocommerce_add_to_cart_validation', array( &$this, 'sniff_order_again_and_readd_to_cart' ), 10 );
		remove_action( 'qsot-events-bulk-edit-settings', array( &$this, 'event_area_bulk_edit_settings' ), 30 );
		remove_filter( 'qsot-events-save-sub-event-settings', array( &$this, 'save_sub_event_settings' ), 10 );
		remove_filter( 'qsot-load-child-event-settings', array( &$this, 'load_child_event_settings' ), 10 );
		remove_action( 'woocommerce_before_delete_order_item', array( &$this, 'woocommerce_before_delete_order_item' ), 10 );
		if ( is_admin() ) {
			remove_action( 'qsot-admin-load-assets-shop_order', array( &$this, 'load_assets_edit_order' ), 10 );
			remove_filter( 'qsot-ticket-selection-templates', array( &$this, 'admin_ticket_selection_templates' ), 10 );
			remove_action( 'woocommerce_order_item_add_line_buttons', array( &$this, 'add_tickets_button' ), 10 );
		}
	}

	// register the assets we might need for this post type
	public function register_assets() {
		// reuseable data
		$url = QSOT::plugin_url();
		$version = QSOT::version();

		// register some scripts
		wp_register_script( 'qsot-event-area-admin', $url . 'assets/js/admin/event-area-admin.js', array( 'qsot-admin-tools' ), $version );
		wp_register_script( 'qsot-event-event-area-settings', $url . 'assets/js/admin/event-area/event-settings.js', array( 'qsot-event-ui' ), $version );
		wp_register_script( 'qsot-admin-ticket-selection', $url . 'assets/js/admin/order/ticket-selection.js', array( 'qsot-admin-tools', 'jquery-ui-dialog', 'qsot-frontend-calendar' ), $version );

		// register all the area type assets
		foreach ( $this->area_types as $area_type )
			$area_type->register_assets();
	}

	// enqueue the needed admin assets on the edit event area page
	public function enqueue_admin_assets_event_area( $exists, $post_id ) {
		wp_enqueue_media();
		wp_enqueue_script( 'qsot-event-area-admin' );
		wp_enqueue_style( 'select2' );

		// setup the js settings for our js
		wp_localize_script( 'qsot-event-area-admin', '_qsot_event_area_admin', array(
			'nonce' => wp_create_nonce( 'do-qsot-ajax' ),
		) );

		// do the same for each registered area type
		foreach ( $this->area_types as $area_type )
			$area_type->enqueue_admin_assets( 'qsot-event-area', $exists, $post_id );
	}

	// enqueue the needed admin assets on the edit event page
	public function enqueue_admin_assets_event( $exists, $post_id ) {
		wp_enqueue_script( 'qsot-event-event-area-settings' );

		// do the same for each registered area type
		foreach ( $this->area_types as $area_type )
			$area_type->enqueue_admin_assets( 'qsot-event', $exists, $post_id );
	}

	// enqueue the frontend assets we need
	public function enqueue_assets( $post ) {
		// figure out the event area type of this event
		$event_area = apply_filters( 'qsot-event-area-for-event', false, $GLOBALS['post'] );
		$area_type = $this->event_area_type_from_event_area( $event_area );

		// if there is a valid area_type, then load it's frontend assets
		if ( is_object( $area_type ) ) {
			$event = apply_filters( 'qsot-get-event', $post, $post );
			$event->event_area = isset( $event->event_area ) && is_object( $event->event_area ) ? $event->event_area : apply_filters( 'qsot-event-area-for-event', null, $event );
			$area_type->enqueue_assets( $event );
		}
	}

	// register the post type with wordpress
	public function register_post_type() {
		// singular and plural forms of the name of this post type
		$single = __( 'Event Area', 'opentickets-community-edition' );
		$plural = __( 'Event Areas', 'opentickets-community-edition' );

		// create a list of labels to use for this post type
		$labels = array(
			'name' => $plural,
			'singular_name' => $single,
			'menu_name' => $plural,
			'name_admin_bar' => $single,
			'add_new' => sprintf( __( 'Add New %s', 'qs-software-manager' ), '' ),
			'add_new_item' => sprintf( __( 'Add New %s', 'qs-software-manager' ), $single),
			'new_item' => sprintf( __( 'New %s', 'qs-software-manager' ), $single ),
			'edit_item' => sprintf( __( 'Edit %s', 'qs-software-manager' ), $single ),
			'view_item' => sprintf( __( 'View %s', 'qs-software-manager' ), $single ),
			'all_items' => sprintf( __( 'All %s', 'qs-software-manager' ), $plural ),
			'search_items' => sprintf( __( 'Search %s', 'qs-software-manager' ), $plural ),
			'parent_item_colon' => sprintf( __( 'Parent %s:', 'qs-software-manager' ), $plural ),
			'not_found' => sprintf( __( 'No %s found.', 'qs-software-manager' ), strtolower( $plural ) ),
			'not_found_in_trash' => sprintf( __( 'No %s found in Trash.', 'qs-software-manager' ), strtolower( $plural ) ),
		);

		// list of args that define the post typ
		$args = apply_filters( 'qsot-event-area-post-type-args', array(
			'label' => $plural,
			'labels' => $labels,
			'description' => __( 'Represents a specific physical location that an event can take place. For instance, a specific conference room at a hotel.', 'opentickets-community-edition' ),
			'public' => false,
			'publicly_queryable' => false,
			'show_ui' => true,
			'show_in_menu' => true,
			'query_var' => false,
			'rewrite' => false,
			'capability_type' => 'post',
			'has_archive' => true,
			'hierarchical' => false,
			'menu_position' => 22,
			'supports' => array( 'title' )
		) );

		register_post_type( 'qsot-event-area', $args );
	}

	// draw the event ticket selection UI
	public function draw_event_area( $content, $event ) {
		// get the event area
		$event_area = isset( $event->event_area ) && is_object( $event->event_area ) ? $event->event_area : apply_filters( 'qsot-event-area-for-event', false, $event->ID );

		// if there is no event area, then bail
		if ( ! is_object( $event_area ) || is_wp_error( $event_area ) )
			return $content;

		// get the event area type
		$event_area->area_type = $area_type = isset( $event_area->area_type ) && is_object( $event_area->area_type ) ? $event_area->area_type : $this->event_area_type_from_event_area( $event_area );

		// get the output of the UI
		$ui = $area_type->render_ui( $event, $event_area );

		// put the UI in the appropriate location, depending on our settings
		if ( 'above' == apply_filters( 'qsot-get-option-value', 'below', 'qsot-synopsis-position' ) )
			return $content . $ui;
		else
			return $ui . $content;
	}

	// draw the featured image for the event area, based on the event area types
	public function draw_event_area_image( $event, $area, $reserved, $total_reserved=false ) {
		// make sure we have the event area type handy
		if ( ! is_object( $area ) || ! isset( $area->area_type ) || ! is_object( $area->area_type ) )
			$area = apply_filters( 'qsot-event-area-for-event', $area, $event->ID );

		// if we still do not have the event area type handy, then bail
		if ( ! is_object( $area ) || ! isset( $area->area_type ) || ! is_object( $area->area_type ) )
			return;

		// otherwise, draw the event area image
		$area->area_type->draw_event_area_image( $area, $event, $reserved );
	}

	// get the event area based on the event
	public function get_event_area_for_event( $current, $event_id ) {
		// normalize the event_id
		if ( is_object( $event_id ) && isset( $event_id->ID ) )
			$event_id = $event_id->ID;

		// if the event id is not an id, then bail
		if ( ! is_numeric( $event_id ) || empty( $event_id ) )
			return new WP_Error( 'invalid_id', __( 'The event id you supplied is invalid.', 'opentickets-community-edition' ) );

		// get the event area from the event
		$event_area_id = get_post_meta( $event_id, '_event_area_id', true );
		if ( empty( $event_area_id ) )
			return new WP_Error( 'invalid_id', __( 'The event area id you supplied is invalid.', 'opentickets-community-edition' ) );

		return apply_filters( 'qsot-get-event-area', false, $event_area_id );
	}

	// get the event area based on the event
	public function get_event_area_type_for_event( $current, $event_id ) {
		static $cache = array();
		// normalize the event_id
		if ( is_object( $event_id ) && isset( $event_id->ID ) )
			$event_id = $event_id->ID;

		// if the event id is not an id, then bail
		if ( ! is_numeric( $event_id ) || empty( $event_id ) )
			return new WP_Error( 'invalid_id', __( 'The event id you supplied is invalid.', 'opentickets-community-edition' ) );

		// if there was a cached version already stored, then use it
		if ( isset( $cache[ $event_id ] ) )
			return $this->area_types[ $cache[ $event_id ] ];

		// get the event area from the event
		$event_area_id = get_post_meta( $event_id, '_event_area_id', true );
		if ( empty( $event_area_id ) )
			return new WP_Error( 'invalid_id', __( 'The event area id you supplied is invalid.', 'opentickets-community-edition' ) );

		// get the event area raw post
		$event_area = get_post( $event_area_id );

		// get the result
		$result = is_object( $event_area ) && isset( $event_area->post_type ) ? $this->event_area_type_from_event_area( $event_area ) : null;

		// if there was a result, then cache it
		if ( is_object( $result ) )
			$cache[ $event_id ] = $result->get_slug();

		return $result;
	}

	// load an event area based on the id
	public function get_event_area( $current, $event_area_id ) {
		// get the event area object
		$event_area = get_post( $event_area_id );
		$event_area->meta = get_post_meta( $event_area->ID );
		foreach ( $event_area->meta as $k => $v )
			$event_area->meta[ $k ] = current( $v );
		$event_area->area_type = $this->event_area_type_from_event_area( $event_area );

		return $event_area;
	}

	// figure out the event area type, based on the post
	public function event_area_type_from_event_area( $post ) {
		// if there are no event area types registered, then bail
		if ( empty( $this->area_types ) )
			return new WP_Error( 'no_types', __( 'There are no registered event area types.', 'opentickets-community-edition' ) );

		// see if the meta value is set, and valid
		$current = get_post_meta( $post->ID, '_qsot-event-area-type', true );

		// if it is set and valid, then use that
		if ( isset( $current ) && is_string( $current ) && ! empty( $current ) && isset( $this->area_types[ $current ] ) )
			return $this->area_types[ $current ];

		// otherwise, cycle through the find type list, and find the first matching type
		foreach ( $this->find_order as $slug ) {
			if ( $this->area_types[ $slug ]->post_is_this_type( $post ) ) {
				update_post_meta( $post->ID, '_qsot-event-area-type', $slug );
				return $this->area_types[ $slug ];
			}
		}

		// if no match was found, then just use the type with the highest priority (least specific)
		$current = end( $this->find_order );
		return $this->area_types[ $current ];
	}

	// function to obtain a list of all the registered event area types
	public function get_event_area_types( $desc_order=false ) {
		// return a list of event_types ordered by priority, either asc (default) or desc (param)
		return ! $desc ? $this->area_types : array_reverse( $this->area_types );
	}

	// allow registration of an event area type
	public function register_event_area_type( &$type_object ) {
		// make sure that the submitted type uses the base class
		if ( ! ( $type_object instanceof QSOT_Base_Event_Area_Type ) )
			throw new Exception( __( 'The supplied event type does not use the QSOT_Base_Event_Type parent class.', 'opentickets-community-edition' ), 12100 );

		// figure out the slug and display name of the submitted event type
		$slug = $type_object->get_slug();

		// add the event area type to the list
		$this->area_types[ $slug ] = $type_object;

		// determine the 'fidn order' for use when searching for the appropriate type
		// default area type should have the highest find_priority
		uasort( $this->area_types, array( &$this, 'uasort_find_priority' ) );
		$this->find_order = array_keys( $this->area_types );

		// sort the list by priority
		uasort( $this->area_types, array( &$this, 'uasort_priority' ) );
	}

	// allow an event area type to be unregistered
	public function deregister_event_area_type( $type ) {
		$slug = '';
		// figure out the slug
		if ( is_string( $type ) )
			$slug = $type;
		elseif ( is_object( $type ) && $type instanceof QSOT_Base_Event_Area_type )
			$slug = $type->get_slug();

		// if there was no slug found, bail
		if ( empty( $slug ) )
			return;

		// if the slug does not coorespond with a registered area type, bail
		if ( ! isset( $this->area_types[ $slug ] ) )
			return;

		unset( $this->area_types[ $slug ] );
	}

	// sort items by $obj->priority()
	public function uasort_priority( $a, $b ) { return $a->get_priority() - $b->get_priority(); }

	// sort items by $obj->find_priority()
	public function uasort_find_priority( $a, $b ) {
		$A = $a->get_find_priority();
		$B = $b->get_find_priority();
		return ( $A !== $B ) ? $A - $B : $a->get_priority() - $b->get_priority();
	}

	// add the event area type metaboxes
	public function add_meta_boxes() {
		// add the event area type metabox
		add_meta_box(
			'qsot-event-area-type',
			__( 'Event Area Type', 'opentickets-community-edition' ),
			array( &$this, 'mb_render_event_area_type' ),
			'qsot-event-area',
			'side',
			'high'
		);

		// add the venue selection to the seating chart ui pages
		add_meta_box(
			'qsot-seating-chart-venue',
			__( 'Venue', 'opentickets-community-edition' ),
			array( &$this, 'mb_render_venue' ),
			'qsot-event-area',
			'side',
			'high'
		);

		// add all the metaboxes for each event area type
		foreach ( $this->area_types as $area_type ) {
			$meta_boxes = $area_type->get_meta_boxes();

			// add each metabox, and a filter that may or may not hide it
			foreach ( $meta_boxes as $meta_box_args ) {
				// get the metabox id and the screen, so that we can use it to create the filter for possibly hiding it
				$id = current( $meta_box_args );
				$screen = isset( $meta_box_args[3] ) ? $meta_box_args[3] : '';

				// add the metabox
				call_user_func_array( 'add_meta_box', $meta_box_args );

				// add the filter that may hide the metabox by default
				add_filter( 'postbox_classes_' . $screen . '_' . $id, array( &$this, 'maybe_hide_meta_box_by_default' ) );
			}
		}
	}

	// filter to maybe hide the current meta box by default
	public function maybe_hide_meta_box_by_default( $classes=array() ) {
		static $area_type = false, $screen = false;
		if ( false === $area_type )
			$area_type = $this->event_area_type_from_event_area( $GLOBALS['post'] );
		// if there is no area_type then bail
		if ( ! is_object( $area_type ) || is_wp_error( $area_type ) )
			return $classes;

		// store the slug of this area type for later use
		$slug = $area_type->get_slug();

		// figure out the screen of the current metabox
		if ( false === $screen ) {
			$screen = get_current_screen();
			if ( ! is_object( $screen ) || ! isset( $screen->id ) ) {
				$screen = null;
				return $classes;
			}
			$screen = $screen->id;
		}
		if ( empty( $screen ) )
			return $classes;

		// based on the screen, find the  of the metabox
		$action = current_filter();
		$id = str_replace( 'postbox_classes_' . $screen . '_', '', $action );
		if ( $id == $action )
			return $classes;

		// if this metabox is not used by the current area type, then hide it by default
		if ( ! $area_type->uses_meta_box( $id, $screen ) )
			$classes[] = 'hide-if-js';

		// add a class indicator for each area_type to this metabox, so that it can be easily hidden or shown with js
		foreach ( $this->area_types as $type ) {
			if ( $type->uses_meta_box( $id, $screen ) )
				$classes[] = 'for-' . $type->get_slug();
			else
				$classes[] = 'not-for-' . $type->get_slug();
		}

		return $classes;
	}

	// draw the metabox that shows the current value for the event area type, and allows that value to be changed
	public function mb_render_event_area_type( $post ) {
		// get the current value
		$current = $this->event_area_type_from_event_area( $post );

		// if there was a problem finding the current type, then display the error
		if ( is_wp_error( $current ) ) {
			foreach ( $current->get_error_codes() as $code )
				foreach ( $current->get_error_messages( $code ) as $msg )
					echo sprintf( '<p>%s</p>', force_balance_tags( $msg ) );
			return;
		}

		// if there is no current type, bail because something is wrong
		if ( empty( $current ) ) {
			echo '<p>' . __( 'There are no registered event area types.', 'opentickets-community-edition' ) . '</p>';
			return;
		}

		$current_slug = $current->get_slug();

		?>
			<ul class="area-types-list">
				<?php foreach ( $this->area_types as $slug => $type ): ?>
					<li class="area-type-<?php echo esc_attr( $slug ) ?>">
						<input type="radio" name="qsot-event-area-type" value="<?php echo esc_attr( $slug ) ?>" id="area-type-<?php echo esc_attr( $slug ) ?>" <?php checked( $current_slug, $slug ) ?> />
						<label for="area-type-<?php echo esc_attr( $slug ) ?>"><?php echo force_balance_tags( $type->get_name() ) ?></label>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php
	}

	// draw the box that allows selection of the venue this seating chart belongs to
	public function mb_render_venue( $post, $mb ) {
		// get a complete list of available venues
		$venues = get_posts( array(
			'post_type' => 'qsot-venue',
			'post_status' => 'any',
			'posts_per_page' => -1,
		) );

		// and determine the current venue for this event_area
		$current = $post->post_parent;

		// draw the form
		?>
			<select name="post_parent" class="widefat">
				<option value="">-- Select Venue --</option>
				<?php foreach ( $venues as $venue ): ?>
					<option <?php selected( $venue->ID, $current ) ?> value="<?php echo esc_attr( $venue->ID ) ?>"><?php echo apply_filters( 'the_title', $venue->post_title, $venue->ID ) ?></option>
				<?php endforeach; ?>
			</select>
		<?php
	}

	// handle the save post action
	public function save_post( $post_id, $post, $updated=false ) {
		// figure out the submitted event area type
		$event_area_type = isset( $_POST['qsot-event-area-type'] ) ? $_POST['qsot-event-area-type'] : null;

		// if the event type is empty, then bail
		if ( empty( $event_area_type ) )
			return;

		// if the selected type is not a valid type, then bail
		if ( ! isset( $this->area_types[ $event_area_type ] ) )
			return;

		// save the event area type
		update_post_meta( $post_id, '_qsot-event-area-type', $event_area_type );

		// run the post type save
		$this->area_types[ $event_area_type ]->save_post( $post_id, $post, $updated );
	}

	// during cart loading from session, we need to make sure we load all preserved keys
	public function load_item_data( $current, $values, $key ) {
		// get a list of all the preserved keys from our event area types, and add it to the list of keys that need to be loaded
		foreach ( apply_filters( 'qsot-ticket-item-meta-keys', array() ) as $k )
			if ( isset( $values[ $k ] ) )
				$current[ $k ] = $values[ $k ];

		// store a backup copy of the quantity, so that if it changes we have something to compare it to later
		$current['_starting_quantity'] = $current['quantity'];

		return $current;
	}

	// add to the list of item data that needs to be preserved
	public function add_item_meta( $item_id, $values ) {
		// get a list of keys that need to be preserved from our event area types, and add each to the list of keys that needs to be saved in the order items when making an item from a cart item
		foreach ( apply_filters( 'qsot-ticket-item-meta-keys', array() ) as $k ) {
			if ( ! isset( $values[ $k ] ) )
				continue;
			wc_update_order_item_meta( $item_id, '_' . $k, $values[ $k ] );
		}
	}

	// add to the list of meta that needs to be hidden when displaying order items
	public function hide_item_meta( $list ) {
		$list[] = '_event_id';
		return array_filter( array_unique( apply_filters( 'qsot-ticket-item-hidden-meta-keys', $list ) ) );
	}

	// on the edit order screen, for each ticket order item, add the 'view' version of the ticket information
	public function before_view_item_meta( $item_id, $item, $product ) {
		self::_draw_item_ticket_info( $item_id, $item, $product, false );
	}

	// on the edit order screen, for each ticket order item, add the 'edit' version of the ticket information
	public function before_edit_item_meta( $item_id, $item, $product ) {
		self::_draw_item_ticket_info( $item_id, $item, $product, true );
	}

	// when saving the order items on the edit order page, we may need to update the reservations table
	public function save_order_items( $order_id, $items ) {
		// if there are no order items that were edited, then bail
		if ( ! isset( $items['order_item_id'] ) || ! is_array( $items['order_item_id'] ) || empty( $items['order_item_id'] ) )
			return;

		// get the order itself. if it is not an order, then bail
		$order = wc_get_order( $order_id );
		if ( ! is_object( $order ) || is_wp_error( $order ) )
			return;

		// cycle through the order items
		foreach ( $order->get_items( 'line_item' ) as $oiid => $item ) {
			// if this item is not on the list of edited items, then skip
			if ( ! in_array( $oiid, $items['order_item_id'] ) )
				continue;

			// if this order item is not a ticket, then skip
			if ( ! apply_filters( 'qsot-item-is-ticket', false, $item ) )
				continue;

			$updates = array();
			// create a container holding all the updates for this item
			foreach ( $items as $key => $list )
				if ( isset( $list[ $oiid ] ) )
					$updates[ $key ] = $list[ $oiid ];

			// get the event_area and zoner for this order item
			$event_area = apply_filters( 'qsot-event-area-for-event', false, $item['event_id'] );

			// if there is no area_type for this event, then skip this item
			if ( ! is_object( $event_area ) || ! isset( $event_area->area_type ) )
				continue;

			// run the update code for this event area on this item
			$event_area->area_type->save_order_item( $order_id, $oiid, $item, $updates, $event_area );
		}
	}

	// add the relevant ticket information and meta to each order item that needs it, along with a change button for event swaps
	protected function _draw_item_ticket_info( $item_id, $item, $product, $edit=false ) {
		// if the product is not a ticket, then never display event meta
		if ( $product->ticket != 'yes' )
			return;

		$event_id = isset( $item['event_id'] ) && $item['event_id'] > 0 ? $item['event_id'] : false;
		?>
			<div class="meta-list ticket-info" rel="ticket-info">
				<?php if ( $edit ): ?>
					<div><a href="#" class="button change-ticket"
						item-id="<?php echo esc_attr( $item_id ) ?>"
						event-id="<?php echo esc_attr( $event_id ) ?>"
						qty="<?php echo esc_attr( $item['qty'] ) ?>"><?php _e( 'Change', 'opentickets-community-edition' ) ?></a></div>
				<?php endif; ?>

				<?php if ( $event_id ): ?>
					<?php
						$event = get_post( $event_id );
						$area_type = apply_filters( 'qsot-event-area-type-for-event', false, $event );
					?>

					<div class="info">
						<strong><?php _e( 'Event:', 'opentickets-community-edition' ) ?></strong>
						<?php echo sprintf( '<a rel="edit-event" target="_blank" href="%s">%s</a>', get_edit_post_link( $event->ID ), apply_filters( 'the_title', $event->post_title, $event->ID ) ) ?>
					</div>

					<?php $area_type->order_item_display( $item, $product, $event ) ?>
				<?php else: ?>
					<div class="info"><strong><?php _e( 'Event:', 'opentickets-community-edition' ) ?></strong> <span class="event-name"><?php _e( '(no event selected)', 'opentickets-community-edition' ) ?></span></div>
				<?php endif; ?>

				<?php do_action( 'qsot-ticket-item-meta', $item_id, $item, $product ) ?>
			</div>
		<?php
	}

	// sync the cart with the tickets we have in the ticket association table. if the ticket is gone from the table, then remove it from the cart (expired timer or manual delete)
	public function sync_cart_tickets() {
		// get the woocommerce core object
		$WC = WC();

		// if we dont have the core object or the cart, then bail
		if ( ! is_object( $WC ) || ! isset( $WC->cart ) || ! is_object( $WC->cart ) )
			return;

		do_action( 'qsot-clear-zone-locks', array( 'customer_id' => QSOT::current_user() ) );

		// if we are in the admin, bail now
		if ( is_admin() )
			return;

		// find all reservations for this user
		// @NOTE: need more uniform way of determining 'reserved' is what we are looking for
		$reserved = 'reserved';
		$results = QSOT_Zoner_Query::instance()->find( array( 'state' => $reserved, 'customer_id' => QSOT::current_user() ) );

		$event_to_area_type = $indexed = array();
		// create an indexed list from those results
		foreach ( $results as $row ) {
			// fill the event to area_type lookup
			$event_to_area_type[ $row->event_id ] = isset( $event_to_area_type[ $row->event_id ] ) ? $event_to_area_type[ $row->event_id ] : apply_filters( 'qsot-event-area-type-for-event', false, get_post( $row->event_id ) );

			// if there is no key for the event, make one
			$indexed[ $row->event_id ] = isset( $indexed[ $row->event_id ] ) ? $indexed[ $row->event_id ] : array();

			// if there is no key for the state, make one
			$indexed[ $row->event_id ][ $row->state ] = isset( $indexed[ $row->event_id ][ $row->state ] ) ? $indexed[ $row->event_id ][ $row->state ] : array();

			// if there is no key for the ticket type, then make one
			$indexed[ $row->event_id ][ $row->state ][ $row->ticket_type_id ] = isset( $indexed[ $row->event_id ][ $row->state ][ $row->ticket_type_id ] )
					? $indexed[ $row->event_id ][ $row->state ][ $row->ticket_type_id ]
					: array();

			// add this row to the indexed key
			$indexed[ $row->event_id ][ $row->state ][ $row->ticket_type_id ][] = $row;
		}

		// cycle through the cart items, and remove any that do not have a matched indexed item
		foreach ( $WC->cart->get_cart() as $key => $item ) {
			// if this is not an item linked to an event, then bail
			if ( ! isset( $item['event_id'] ) )
				continue;

			// get the relevant ids
			$eid = $item['event_id'];
			$pid = $item['product_id'];

			$quantity = 0;
			// if there is a basic indexed matched key for this item, then find the appropriate quantity to use
			if ( isset( $indexed[ $eid ], $indexed[ $eid ][ $reserved ], $indexed[ $eid ][ $reserved ][ $pid ] ) ) {
				// if there is not an appropriate area type for this event, then just pass it through using the indexed item quantity. this is the generic method, list_pluck
				if ( ! isset( $event_to_area_type[ $eid ] ) || ! is_object( $event_to_area_type[ $eid ] ) || is_wp_error( $event_to_area_type[ $eid ] ) ) {
					$quantity = array_sum( wp_list_pluck( $indexed[ $eid ][ $reserved ][ $pid ], 'quantity' ) );
				// otherwise use the method of finding the quantity defined by the area_type itself
				} else {
					$quantity = $event_to_area_type[ $eid ]->cart_item_match_quantity( $item, $indexed[ $eid ][ $reserved ][ $pid ] );
				}
			}

			// update the item quantity, either by removing it, or by setting it to the appropriate value
			$WC->cart->set_quantity( $key, $quantity );
		}
	}

	// clear out reservations that have temporary zone locks, based on the supplied information
	public function clear_zone_locks( $args='' ) {
		// normalize the input
		$args = wp_parse_args( $args, array(
			'event_id' => '',
			'customer_id' => '',
		) );

		// figure out a complete list of all temporary stati
		$stati = array();
		foreach ( $this->area_types as $slug => $type ) {
			$zoner = $type->get_zoner();
			if ( is_object( $zoner ) && ( $tmp = $zoner->get_temp_stati() ) ) {
				foreach ( $tmp as $key => $v )
					if ( $v[1] > 0 )
						$stati[ $v[0] ] = $v[1];
			}
		}

		// if there are no defined temp states, then bail
		if ( empty( $stati ) )
			return;

		global $wpdb;
		// start constructing the query
		$q = 'delete from ' . $wpdb->qsot_event_zone_to_order . ' where ';

		// construct the stati part of the query
		$stati_q = array();
		foreach ( $stati as $slug => $interval )
			$stati_q[] = $wpdb->prepare( '(state = %s and since < NOW() - INTERVAL %d SECOND)', $slug, $interval );
		$q .= '(' . implode( ' or ', $stati_q ) . ')';

		// if the event_id was specified, then use it
		if ( '' !== $args['event_id'] && null !== $args['event_id'] )
			$q .= $wpdb->prepare( ' and event_id = %d', $args['event_id'] );

		// if the customer_id was specified, then use it
		if ( '' !== $args['customer_id'] && null !== $args['customer_id'] )
			$q .= $wpdb->prepare( ' and session_customer_id = %s', $args['customer_id'] );

		$wpdb->query( $q );
	}

	// when the order status changes, change the status of the order tickets
	public function order_status_changed( $order_id, $old_status, $new_status ) {
		// if the status is a status that should have confirmed tickets, then .... make them confirmed
		if ( in_array( $new_status, apply_filters( 'qsot-zoner-confirmed-statuses', array( 'on-hold', 'processing', 'completed' ) ) ) ) {
			// load the order
			$order = wc_get_order( $order_id );
			
			// cycle through the order items, and update all the ticket items to confirmed
			foreach ( $order->get_items() as $item_id => $item ) {
				// only do this for order items that are tickets
				if ( ! apply_filters( 'qsot-item-is-ticket', false, $item ) )
					continue;

				// get the event, area_type and zoner for this item
				$event = get_post( $item['event_id'] );
				$event_area = apply_filters( 'qsot-event-area-for-event', false, $event );
				$area_type = is_object( $event_area ) ? $event_area->area_type : null;

				// if any of the data is missing, the skip this item
				if ( ! is_object( $event ) || ! is_object( $event_area ) || ! is_object( $area_type ) )
					continue;

				// have the event_area determine how to update the order item info in the ticket table
				$result = $area_type->confirm_tickets( $item, $item_id, $order, $event, $event_area );

				// notify externals of the change
				do_action( 'qsot-confirmed-ticket', $order, $item, $item_id, $result );
			}
		}
	}
	
	// separate function to handle the order status changes to 'cancelled'
	public function order_status_changed_pending( $order_id, $old_status, $new_status ) {
		// if the order is actually getting put back into pending, or any other status that should be considered an 'unconfirm' step
		if ( in_array( $new_status, apply_filters( 'qsot-zoner-unconfirm-statuses', array( 'pending' ) ) ) ) {
			// load the order
			$order = wc_get_order( $order_id );
			
			// cycle through the order items, and update all the ticket items to confirmed
			foreach ( $order->get_items() as $item_id => $item ) {
				// only do this for order items that are tickets
				if ( ! apply_filters( 'qsot-item-is-ticket', false, $item ) )
					continue;

				// get the event, area_type and zoner for this item
				$event = get_post( $item['event_id'] );
				$event_area = apply_filters( 'qsot-event-area-for-event', false, $event );
				$area_type = is_object( $event_area ) ? $event_area->area_type : null;

				// if any of the data is missing, the skip this item
				if ( ! is_object( $event ) || ! is_object( $event_area ) || ! is_object( $area_type ) )
					continue;

				// have the event_area determine how to update the order item info in the ticket table
				$result = $area_type->unconfirm_tickets( $item, $item_id, $order, $event, $event_area );

				// notify externals of the change
				do_action( 'qsot-unconfirmed-ticket', $order, $item, $item_id, $result );
			}
		}
	}
	
	// separate function to handle the order status changes to 'cancelled'
	public function order_status_changed_cancel( $order_id, $old_status, $new_status ) {
		// if the order is actually getting cancelled, or any other status that should be considered an 'cancelled' step
		if ( in_array( $new_status, apply_filters( 'qsot-zoner-cancelled-statuses', array( 'cancelled' ) ) ) ) {
			// load the order
			$order = wc_get_order( $order_id );
			
			// cycle through the order items, and update all the ticket items to confirmed
			foreach ( $order->get_items() as $item_id => $item ) {
				// only do this for order items that are tickets
				if ( ! apply_filters( 'qsot-item-is-ticket', false, $item ) )
					continue;

				// get the event, area_type and zoner for this item
				$event = get_post( $item['event_id'] );
				$event_area = apply_filters( 'qsot-event-area-for-event', false, $event );
				$area_type = is_object( $event_area ) ? $event_area->area_type : null;

				// if any of the data is missing, the skip this item
				if ( ! is_object( $event ) || ! is_object( $event_area ) || ! is_object( $area_type ) )
					continue;

				// have the event_area determine how to update the order item info in the ticket table
				$result = $area_type->cancel_tickets( $item, $item_id, $order, $event, $event_area );

				// notify externals of the change
				do_action( 'qsot-cancelled-ticket', $order, $item, $item_id, $result );

				// remove the order item
				wc_delete_order_item( $item_id );
			}
		}
	}

	// fix the problem where ppl click order again
	public function adjust_order_again_items( $meta, $item, $order ) {
		// if the original item is not for an event, then bail now
		if ( ! isset( $item['event_id'] ) )
			return $meta;

		// mark the meta as being an order_again item
		$meta['_order_again'] = true;

		// cycle through the old meta of the original item, and copy any relevant meta to the new item's meta
		if ( isset( $item['item_meta'] ) ) foreach ( $item['item_meta'] as $key => $values ) {
			if ( in_array( $key, apply_filters( 'qsot-order-ticket-again-meta-keys', array( '_event_id' ) ) ) ) {
				$meta[ $key ] = current( $values );
			}
		}

		return $meta;
	}

	// when order_again is hit, items are discretely added to the new cart. during that process, sniff out any tickets, and add them to the cart a different way
	public function sniff_order_again_and_readd_to_cart( $passes_validation, $product_id, $quantity, $variation_id=0, $variations='', $cart_item_data=array() ) {
		// if the marker is not present, then pass through
		if ( ! isset( $cart_item_data['_order_again'] ) )
			return $passes_validation;

		unset( $cart_item_data['_order_again'] );
		// otherwise, attempt to add the ticket to the cart via our ticket selection logic, instead of the standard reorder way
		$res = apply_filters( 'qsot-order-again-add-to-cart-pre', null, $product_id, $quantity, $variation_id, $variations, $cart_item_data );

		// if another plugin has not done it's own logic here, then perform the default logic
		if ( null === $res ) {
			$res = apply_filters( 'qsot-zoner-reserve-current-user', false, $cart_item_data['_event_id'], $product_id, $quantity );
		}

		// if the results are a wp_error, then add that as a notice
		if ( is_wp_error( $res ) ) {
			foreach ( $res->get_error_codes() as $code )
				foreach ( $res->get_error_messages( $code ) as $msg )
					wc_add_notice( $msg, 'error' );
		}

		return false;
	}

	// add the form field that controls the event area selection for events, on the edit event page
	public function event_area_bulk_edit_settings($post, $mb) {
		// get a list of all event areas
		$eaargs = array(
			'post_type' => 'qsot-event-area',
			'post_status' => array( 'publish', 'inherit' ),
			'posts_per_page' => -1,
			'fields' => 'ids',
		);
		$area_ids = get_posts( $eaargs );

		// render the form fields
		?>
			<div class="setting-group">
				<div class="setting" rel="setting-main" tag="event-area">
					<div class="setting-current">
						<span class="setting-name"><?php _e( 'Event Area:', 'opentickets-community-edition' ) ?></span>
						<span class="setting-current-value" rel="setting-display"></span>
						<a class="edit-btn" href="#" rel="setting-edit" scope="[rel=setting]" tar="[rel=form]"><?php _e( 'Edit', 'opentickets-community-edition' ) ?></a>
						<input type="hidden" name="settings[event-area]" value="" scope="[rel=setting-main]" rel="event-area" />
					</div>
					<div class="setting-edit-form" rel="setting-form">
						<select name="event-area">
							<option value="0"><?php _e( '-None-', 'opentickets-community-edition' ) ?></option>
							<?php foreach ( $area_ids as $area_id ): ?>
								<?php
									// get the event area
									$event_area = apply_filters( 'qsot-get-event-area', false, $area_id );

									// get the capacity of the event area. this is used to update the 'capacity' part of the calendar blocks in the admin
									$capacity = isset( $event_area->meta, $event_area->meta['_capacity'] ) ? (int) $event_area->meta['_capacity'] : get_post_meta( $event_area->ID, '_capacity', true );

									// if the area_type is set, then use it to find the appropriate display name of this event area
									if ( isset( $event_area->area_type ) && is_object( $event_area->area_type ) )
										$display_name = $event_area->area_type->get_event_area_display_name( $event_area );
									// otherwise, use a generic method
									else
										$display_name = apply_filters( 'the_title', $event_area->post_title, $event_area->ID );
								?>
								<option value="<?php echo esc_attr( $event_area->ID ) ?>" venue-id="<?php echo $event_area->post_parent ?>" capacity="<?php echo esc_attr( $capacity ) ?>"><?php echo $display_name; ?></option>
							<?php endforeach; ?>
						</select>
						<div class="edit-setting-actions">
							<input type="button" class="button" rel="setting-save" value="<?php _e( 'OK', 'opentickets-community-edition' ) ?>" />
							<a href="#" rel="setting-cancel"><?php _e( 'Cancel', 'opentickets-community-edition' ) ?></a>
						</div>
					</div>
				</div>
			</div>
		<?php
	}

	// when saving a sub event, we need to make sure to save what event area it belongs to
	public function save_sub_event_settings( $settings, $parent_id, $parent ) {
		// cache the product price lookup becasue it can get heavy
		static $ea_price = array();

		// if the ea_id was in the submitted data (from the saving of an edit-event screen in the admin), then
		if ( isset( $settings['submitted'], $settings['submitted']->event_area ) ) {
			// add the event_area_id to the meta to save for the individual child event
			$settings['meta']['_event_area_id'] = $settings['submitted']->event_area;

			// also record the price_option product _price, because it will be used by the display options plugin when showing the events in a 'filtered by price' shop page
			if ( isset( $ea_price[ $settings['submitted']->event_area ] ) ) {
				$settings['meta']['_price'] = $ea_price[ $settings['submitted']->event_area ];
			// if that price has not been cached yet, then look it up
			} else {
				$price = 0;
				$product_id = get_post_meta( $settings['submitted']->event_area, '_pricing_options', true );
				if ( $product_id > 0 )
					$price = get_post_meta( $product_id, '_price', true );
				$ea_price[ $settings['submitted']->event_area ] = $settings['meta']['_price'] = $price;
			}

			// get the event area
			$event_area = apply_filters( 'qsot-get-event-area', false, $settings['submitted']->event_area );

			// allow the event area to add it's own save logic
			if ( is_object( $event_area ) && ! is_wp_error( $event_area ) && isset( $event_area->area_tye ) && is_object( $event_area->area_type ) )
				$settings['meta'] = $event_area->area_type->save_event_settings( $settings['meta'], $settings );
		}

		return $settings;
	}

	// during page load of the edit event page, we need to load all the data about the child events. this will add the event_area data to the child event
	public function load_child_event_settings( $settings, $defs, $event ) {
		// if we know the event to set the data on, then...
		if ( is_object( $event ) && isset( $event->ID ) ) {
			// load the event area id that is currently set for this sub event
			$ea_id = get_post_meta( $event->ID, '_event_area_id', true);

			// add it to the list of data that is used on the frontend
			$settings['event-area'] = (int)$ea_id;

			// if we found an event_area, then also add the capacity to the data, for possible use
			if ( $ea_id )
				$settings['capacity'] = get_post_meta( $ea_id, '_capacity', true );
		}

		return $settings;
	}

	// during the editing of an order in the admin (new or existing), we may need to add/change ticket reservations. to do this, we need to have some js templates to help. this function aggregates them
	public function admin_ticket_selection_templates( $list, $exists, $order_id ) {
		// create a list of args to send to the loaded templates
		$args = array( 'list' => $list, 'exists' => $exists, 'order_id' => $order_id );

		// load the generic templates
		$list['dialog-shell'] = QSOT_Templates::maybe_include_template( 'admin/ticket-selection/dialog-shell.php', $args );
		$list['transition'] = QSOT_Templates::maybe_include_template( 'admin/ticket-selection/transition.php', $args );

		// aggregate all the templates from each of the known area_types
		foreach ( $this->area_types as &$area_type )
			$list = $area_type->get_admin_templates( $list, 'ticket-selection', $args );

		return $list;
	}

	// load the assets we need on the edit order page
	public function load_assets_edit_order( $exists, $order_id ) {
		// calendar assets
		wp_enqueue_script( 'qsot-frontend-calendar' );
		wp_enqueue_style( 'qsot-frontend-calendar-style' );

		// initialize the calendar settings
		do_action( 'qsot-calendar-settings', get_post( $order_id ), true, '' );

		// load assets for ticket selection process
		//wp_enqueue_style( 'wp-jquery-ui-dialog' );
		wp_enqueue_script( 'qsot-admin-ticket-selection' );
		wp_localize_script( 'qsot-admin-ticket-selection', '_qsot_admin_ticket_selection', array(
			'nonce' => wp_create_nonce( 'do-qsot-admin-ajax' ),
			'templates' => apply_filters( 'qsot-ticket-selection-templates', array(), $exists, $order_id ),
		) );

		// do the same for each registered area type
		foreach ( $this->area_types as $area_type )
			$area_type->enqueue_admin_assets( 'shop_order', $exists, $order_id );
	}

	// add the button that allows an admin to add a ticket to an order
	public function add_tickets_button( $order ) {
		?><button type="button" class="button add-order-tickets" rel="add-tickets-btn"><?php _e( 'Add tickets', 'opentickets-community-edition' ); ?></button><?php
	}

	// when an order item is removed, we need to also remove the associated tickets
	public function woocommerce_before_delete_order_item( $item_id ) {
		global $wpdb;

		// get the event for the ticket we are deleting. if there is no event, then bail
		$event_id = intval( wc_get_order_item_meta( $item_id, '_event_id', true ) );
		if ( $event_id <= 0 || ! ( $event = get_post( $event_id ) ) || ! is_object( $event ) || 'qsot-event' !== $event->post_type )
			return;

		// figure out the event area and area type of the event. if there is not a valid one, then bail
		$event_area = apply_filters( 'qsot-event-area-for-event', false, $event );
		if ( ! is_object( $event_area ) || ! isset( $event_area->area_type ) || ! is_object( $event_area->area_type ) )
			return;

		global $wpdb;
		// get the order and order item information. if they dont exist, then bail
		$order_id = intval( $wpdb->get_var( $wpdb->prepare( 'select order_id from ' . $wpdb->prefix . 'woocommerce_order_items where order_item_id = %d', $item_id ) ) );
		$order = wc_get_order( $order_id );
		if ( ! is_object( $order ) || is_wp_error( $order ) )
			return;
		$items = $order->get_items();
		$item = isset( $items[ $item_id ] ) ? $items[ $item_id ] : false;
		if ( empty( $item ) )
			return;

		// remove the reservations
		$event_area->area_type->cancel_tickets( $item, $item_id, $order, $event, $event_area );
	}

	// load the event area information and attach it to the ticket information. used when rendering the ticket
	public function add_event_area_data( $current, $oiid, $order_id ) {
		// skip this function if the ticket has not already been loaded, or if it is a wp error
		if ( ! is_object( $current ) || is_wp_error( $current ) )
			return $current;

		// also skip this function if the event info has not been loaded
		if ( ! isset( $current->event, $current->event->ID ) )
			return $current;

		// move the event area object to top level scope so we dont have to dig for it
		$current->event_area = apply_filters( 'qsot-event-area-for-event', false, $current->event );
		if ( isset( $current->event_area->area_type) && is_object( $current->event_area->area_type ) )
			$current = $current->event_area->area_type->compile_ticket( $current );

		return $current;
	}

	// load the event details for the admin ticket selection interface
	public function admin_ajax_load_event( $resp, $event ) {
		// if the event does not exist, then bail
		if ( ! is_object( $event ) ) {
			$resp['e'][] = __( 'Could not find the new event.', 'opentickets-community-edition' );
			return $resp;
		}
		
		// attempt to load the event_area for that event, and if not loaded, then bail
		$event_area = apply_filters( 'qsot-event-area-for-event', false, $event );
		if ( ! is_object( $event_area ) || ! isset( $event_area->area_type ) || ! is_object( $event_area->area_type ) || ! ( $zoner = $event_area->area_type->get_zoner() ) ) {
			$resp['e'][] = __( 'Could not find the new event\'s event area.', 'opentickets-community-edition' );
			return $resp;
		}
		$stati = $zoner->get_stati();

		// load the order and if it does not exist, bail
		$order = wc_get_order( isset( $_POST['order_id'] ) ? (int) $_POST['order_id'] : false );
		if ( ! is_object( $order ) || is_wp_error( $order ) ) {
			$resp['e'][] = __( 'Could not find that order.', 'opentickets-community-edition' );
			return $resp;
		}

		// start constructing the response
		$resp['s'] = true;
		$resp['data'] = array(
			'id' => $event->ID,
			'name' => apply_filters( 'the_title', $event->post_title, $event->ID ),
			'area_type' => $event_area->area_type->get_slug(),
		);
		$resp['data'] = $event_area->area_type->admin_ajax_load_event( $resp['data'], $event, $event_area, $order );

		return $resp;
	}

	// setup the admin settings related to the event areas and ticket selection ui
	protected function _setup_admin_options() {
		// the the plugin settings object
		$options = QSOT_Options::instance();

		// setup the default values
		$options->def( 'qsot-reserve-button-text', __( 'Reserve', 'opentickets-community-edition' ) ); 
		$options->def( 'qsot-update-button-text', __( 'Update', 'opentickets-community-edition' ) ); 
		$options->def( 'qsot-proceed-button-text', __( 'Proceed to Cart', 'opentickets-community-edition' ) ); 


		// Ticket UI settings
		$options->add( array(
			'order' => 300, 
			'type' => 'title',
			'title' => __( 'Ticket Selection UI', 'opentickets-community-edition' ),
			'id' => 'heading-ticket-selection-2',
			'page' => 'frontend',
		) ); 

		// Reserve button
		$options->add( array(
			'order' => 305, 
			'id' => 'qsot-reserve-button-text',
			'default' => $options->{'qsot-reserve-button-text'},
			'type' => 'text',
			'title' => __( 'Reserve Button', 'opentickets-community-edition' ),
			'desc' => __( 'Label for the Reserve Button on the Ticket Selection UI.', 'opentickets-community-edition' ),
			'page' => 'frontend',
		) ); 

		// Update button
		$options->add( array(
			'order' => 310, 
			'id' => 'qsot-update-button-text',
			'default' => $options->{'qsot-update-button-text'},
			'type' => 'text',
			'title' => __( 'Update Button', 'opentickets-community-edition' ),
			'desc' => __( 'Label for the Update Button on the Ticket Selection UI.', 'opentickets-community-edition' ),
			'page' => 'frontend',
		) ); 

		// Update button
		$options->add( array(
			'order' => 315, 
			'id' => 'qsot-proceed-button-text',
			'default' => $options->{'qsot-proceed-button-text'},
			'type' => 'text',
			'title' => __( 'Proceed to Cart Button', 'opentickets-community-edition' ),
			'desc' => __( 'Label for the Proceed to Cart Button on the Ticket Selection UI.', 'opentickets-community-edition' ),
			'page' => 'frontend',
		) ); 

		// End Ticket UI settings
		$options->add( array(
			'order' => 399, 
			'type' => 'sectionend',
			'id' => 'heading-ticket-selection-1',
			'page' => 'frontend',
		) ); 
	}

	// setup the table names used by the general admission area type, for the current blog
	public function setup_table_names() {
		global $wpdb;
		$wpdb->qsot_event_zone_to_order = $wpdb->prefix . 'qsot_event_zone_to_order';
	}

	// define the tables that are used by this area type
	public function setup_tables( $tables ) {
    global $wpdb;
		// the primary table that links everything together
    $tables[ $wpdb->qsot_event_zone_to_order ] = array(
      'version' => '1.3.0',
      'fields' => array(
				'event_id' => array( 'type' => 'bigint(20) unsigned' ), // post of type qsot-event
				'order_id' => array( 'type' => 'bigint(20) unsigned' ), // post of type shop_order (woocommerce)
				'quantity' => array( 'type' => 'smallint(5) unsigned' ), // some zones can have more than 1 capacity, so we need a quantity to designate how many were purchased ina given zone
				'state' => array( 'type' => 'varchar(20)' ), // word descriptor for the current state. core states are interest, reserve, confirm, occupied
				'since' => array( 'type' => 'timestamp', 'default' => 'CONST:|CURRENT_TIMESTAMP|' ), // when the last action took place. used for lockout clearing
				'mille' => array( 'type' => 'smallint(4)', 'default' => '0' ), // the mille seconds for 'since'. experimental
				'session_customer_id' => array('type' => 'varchar(150)'), // woo session id for linking a ticket to a user, before the order is actually created (like interest and reserve statuses)
				'ticket_type_id' => array( 'type' => 'bigint(20) unsigned', 'default' => '0' ), // product_id of the woo product that represents the ticket that was purchased/reserved
				'order_item_id' => array( 'type' => 'bigint(20) unsigned', 'default' => '0' ), // order_item_id of the order item that represents this ticket. present after order creation
      ),   
      'keys' => array(
        'KEY evt_id (event_id)',
        'KEY ord_id (order_id)',
        'KEY oiid (order_item_id)',
				'KEY stt (state)',
      ),
			'pre-update' => array(
				'when' => array(
					'exists' => array(
						'alter ignore table ' . $wpdb->qsot_event_zone_to_order . ' drop index `evt_id`',
						'alter ignore table ' . $wpdb->qsot_event_zone_to_order . ' drop index `ord_id`',
						'alter ignore table ' . $wpdb->qsot_event_zone_to_order . ' drop index `oiid`',
						'alter ignore table ' . $wpdb->qsot_event_zone_to_order . ' drop index `stt`',
					),
				),
			),
    );   

    return $tables;
	}
}

if ( defined( 'ABSPATH' ) && function_exists( 'add_action' ) )
	QSOT_Post_Type_Event_Area::instance();