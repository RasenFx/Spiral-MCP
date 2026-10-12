<?php
/**
 * Formularios de Contact Form 7 (Fase 9): listar, leer, crear, editar y borrar
 * formularios, con su plantilla de campos, el correo, los mensajes y los ajustes
 * adicionales. Solo se registran si Contact Form 7 está activo.
 *
 * Cambiar a quién se envía el correo (destinatario, cabeceras Cc/Bcc o adjuntos)
 * exige "confirm": true: así un agente no puede desviar los mensajes del sitio sin
 * que el usuario lo sepa.
 *
 * @package Spiral_MCP_Control
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Helpers
 * ---------------------------------------------------------------------- */

/** ¿Está Contact Form 7 activo? */
function spiral_mcp_cf7_active() {
	return class_exists( 'WPCF7_ContactForm' ) && function_exists( 'wpcf7_save_contact_form' );
}

/** Propiedades editables de un formulario de CF7. */
function spiral_mcp_cf7_props() {
	return array( 'form', 'mail', 'mail_2', 'messages', 'additional_settings' );
}

/** Claves del correo que deciden a quién llegan los mensajes. */
function spiral_mcp_cf7_sensitive_mail_keys() {
	return array( 'recipient', 'additional_headers', 'attachments' );
}

/**
 * Formulario a partir de "id": ID numérico, hash (o su prefijo, como en el shortcode) o título exacto.
 * Devuelve WPCF7_ContactForm o WP_Error.
 */
function spiral_mcp_cf7_resolve( $id ) {
	$id   = trim( (string) $id );
	$form = null;
	if ( '' === $id ) {
		return new WP_Error( 'spiral_mcp_bad_input', 'Indica "id": el ID, el hash del shortcode o el título del formulario (ver spiral-mcp/list-forms).' );
	}
	if ( ctype_digit( $id ) ) {
		$form = wpcf7_contact_form( (int) $id );
	}
	if ( ! $form && preg_match( '/^[0-9a-f]{7,}$/', $id ) ) {
		$form = wpcf7_get_contact_form_by_hash( $id );
	}
	if ( ! $form ) {
		$form = wpcf7_get_contact_form_by_title( $id );
	}
	if ( ! $form ) {
		return new WP_Error( 'spiral_mcp_form_not_found', sprintf( 'No existe el formulario "%s". Mira spiral-mcp/list-forms.', $id ), array( 'status' => 404 ) );
	}
	return $form;
}

/** ID numérico del formulario para el registro (la entrada puede traer el hash o el título). */
function spiral_mcp_cf7_log_id( $input, $result ) {
	if ( is_array( $result ) && ! empty( $result['id'] ) ) {
		return (int) $result['id'];
	}
	return (string) ( ( (array) $input )['id'] ?? '' );
}

/** Valida la configuración (como al guardar en wp-admin) y devuelve la lista de problemas. */
function spiral_mcp_cf7_config_errors( WPCF7_ContactForm $form, $save = false ) {
	if ( ! class_exists( 'WPCF7_ConfigValidator' ) || ( function_exists( 'wpcf7_validate_configuration' ) && ! wpcf7_validate_configuration() ) ) {
		return array();
	}
	$validator = new WPCF7_ConfigValidator( $form );
	$validator->validate();
	if ( $save ) {
		$validator->save();
	}
	$out = array();
	foreach ( $validator->collect_error_messages( array( 'decodes_html_entities' => true ) ) as $section => $messages ) {
		foreach ( $messages as $m ) {
			$out[] = array( 'section' => $section, 'message' => wp_strip_all_tags( $m['message'] ) );
		}
	}
	return $out;
}

/** Campos del formulario (form-tags) y avisos de etiquetas que CF7 no reconoce. */
function spiral_mcp_cf7_fields( WPCF7_ContactForm $form ) {
	$fields = array();
	foreach ( $form->scan_form_tags() as $tag ) {
		$field = array(
			'type'     => $tag->type,
			'basetype' => $tag->basetype,
			'name'     => $tag->name,
			'required' => $tag->is_required(),
		);
		if ( $tag->options ) {
			$field['options'] = array_values( $tag->options );
		}
		if ( $tag->raw_values ) {
			$field['values'] = array_values( $tag->raw_values );
		}
		if ( '' !== (string) $tag->content ) {
			$field['content'] = $tag->content;
		}
		$fields[] = $field;
	}
	return $fields;
}

/** Etiquetas entre corchetes de la plantilla cuyo tipo no existe (CF7 las imprime como texto). */
function spiral_mcp_cf7_unknown_tags( $template ) {
	$manager = WPCF7_FormTagsManager::get_instance();
	$unknown = array();
	if ( preg_match_all( '/\[([a-zA-Z_][0-9a-zA-Z:._-]*\*?)[\s\]]/', (string) $template, $m ) ) {
		foreach ( array_unique( $m[1] ) as $type ) {
			if ( ! $manager->tag_type_exists( $type ) ) {
				$unknown[] = $type;
			}
		}
	}
	return $unknown;
}

/**
 * Avisos tras leer o guardar: etiquetas que CF7 no reconoce y textos del correo que
 * CF7 ha recortado al guardar (sin unfiltered_html quita lo que parece HTML, p. ej. <[your-email]>).
 */
function spiral_mcp_cf7_warnings( WPCF7_ContactForm $form, array $input ) {
	$warnings = array();
	$unknown  = spiral_mcp_cf7_unknown_tags( $form->prop( 'form' ) );
	if ( $unknown ) {
		$warnings[] = sprintf( 'Etiquetas que Contact Form 7 no reconoce (se verán como texto): %s.', implode( ', ', $unknown ) );
	}
	foreach ( array( 'mail', 'mail_2' ) as $prop ) {
		if ( isset( $input[ $prop ]['body'] ) && trim( str_replace( "\r\n", "\n", (string) $input[ $prop ]['body'] ) ) !== (string) ( $form->prop( $prop )['body'] ?? '' ) ) {
			$warnings[] = sprintf( 'Contact Form 7 ha modificado el cuerpo de "%s" al guardarlo (quita lo que parece HTML, como <[your-email]>). Revísalo con get-form; para poner un email entre signos usa ( ) o [ ].', $prop );
		}
	}
	return $warnings;
}

/** Datos completos de un formulario (para get-form y para los snapshots). */
function spiral_mcp_cf7_export( WPCF7_ContactForm $form ) {
	return array(
		'title'      => $form->title(),
		'locale'     => $form->locale(),
		'hash'       => (string) get_post_meta( $form->id(), '_hash', true ),
		'properties' => array_intersect_key( $form->get_properties(), array_flip( spiral_mcp_cf7_props() ) ),
	);
}

/**
 * Dónde se usa cada formulario: contenidos (con o sin layout de YOOtheme) y widgets
 * que contienen su shortcode. Devuelve [ form_id => [ … ] ].
 */
function spiral_mcp_cf7_usage() {
	global $wpdb;
	$found = array();
	$add   = function ( $ref, $text, $where ) use ( &$found ) {
		if ( ! preg_match_all( '/\[contact-form(?:-7)?\s[^\]]*?\bid=(?:\\\\?["\'])?([0-9a-f]+)/i', (string) $text, $m ) ) {
			return;
		}
		foreach ( array_unique( $m[1] ) as $ref_id ) {
			$form = ctype_digit( $ref_id ) && strlen( $ref_id ) < 7 ? wpcf7_contact_form( (int) $ref_id ) : null;
			if ( ! $form ) {
				$form = wpcf7_get_contact_form_by_hash( $ref_id );
			}
			if ( ! $form && ctype_digit( $ref_id ) ) {
				$form = wpcf7_contact_form( (int) $ref_id );
			}
			if ( $form ) {
				$found[ $form->id() ][] = $where;
			}
		}
	};
	$rows = $wpdb->get_results( "SELECT ID, post_title, post_type, post_status, post_content FROM {$wpdb->posts} WHERE post_content LIKE '%[contact-form%' AND post_type NOT IN ('revision','wpcf7_contact_form') AND post_status <> 'trash' LIMIT 500" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	foreach ( $rows as $row ) {
		$add( $row->ID, $row->post_content, array( 'type' => 'post', 'id' => (int) $row->ID, 'title' => $row->post_title, 'post_type' => $row->post_type, 'status' => $row->post_status, 'path' => wp_make_link_relative( get_permalink( $row->ID ) ) ) );
	}
	$options = $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'widget\\_%' AND option_value LIKE '%contact-form%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	foreach ( $options as $opt ) {
		$base = substr( $opt->option_name, 7 );
		foreach ( (array) maybe_unserialize( $opt->option_value ) as $n => $instance ) {
			if ( is_array( $instance ) ) {
				$add( $n, wp_json_encode( $instance, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), array( 'type' => 'widget', 'id' => $base . '-' . $n ) );
			}
		}
	}
	return $found;
}

/** Resumen de un formulario para list-forms y las respuestas de escritura. */
function spiral_mcp_cf7_summary( WPCF7_ContactForm $form, $usage = null ) {
	$out = array(
		'id'        => $form->id(),
		'hash'      => $form->hash(),
		'title'     => $form->title(),
		'shortcode' => $form->shortcode(),
		'locale'    => $form->locale(),
		'field_count' => count( array_filter( $form->scan_form_tags(), function ( $t ) {
			return '' !== $t->name;
		} ) ),
		'mail_to'   => (string) ( $form->prop( 'mail' )['recipient'] ?? '' ),
	);
	if ( null !== $usage ) {
		$out['used_in'] = $usage[ $form->id() ] ?? array();
	}
	return $out;
}

/**
 * Mezcla la entrada de una ability con las propiedades actuales. "mail" y "mail_2"
 * se mezclan por claves (CF7 vaciaría las que no se envían); "messages" también.
 */
function spiral_mcp_cf7_merge_input( array $input, array $current ) {
	$data = array();
	if ( isset( $input['template'] ) ) {
		$data['form'] = (string) $input['template'];
	}
	foreach ( array( 'mail', 'mail_2', 'messages' ) as $prop ) {
		if ( isset( $input[ $prop ] ) && is_array( $input[ $prop ] ) ) {
			$data[ $prop ] = array_merge( (array) ( $current[ $prop ] ?? array() ), $input[ $prop ] );
		}
	}
	if ( isset( $input['additional_settings'] ) ) {
		$data['additional_settings'] = (string) $input['additional_settings'];
	}
	return $data;
}

/**
 * Lo que decide a quién llega un correo, normalizado para comparar: el destinatario,
 * solo las cabeceras To/Cc/Bcc (Reply-To no envía nada) y los adjuntos. Un destinatario
 * que solo usa [_site_admin_email] o campos de email del formulario (lo que escribe el
 * visitante) no cuenta: no desvía los mensajes a una dirección fija.
 */
function spiral_mcp_cf7_mail_target( $mail, $key, $email_fields ) {
	$value = trim( (string) ( $mail[ $key ] ?? '' ) );
	if ( 'additional_headers' === $key ) {
		$lines = array();
		foreach ( preg_split( '/\r\n|\n/', $value ) as $line ) {
			if ( preg_match( '/^\s*(to|cc|bcc)\s*:/i', $line ) ) {
				$lines[] = strtolower( trim( $line ) );
			}
		}
		sort( $lines );
		return implode( "\n", $lines );
	}
	if ( 'recipient' === $key ) {
		$safe = array_merge( array( '_site_admin_email' ), $email_fields );
		$all  = array_filter( array_map( 'trim', explode( ',', $value ) ) );
		$bad  = array_filter( $all, function ( $part ) use ( $safe ) {
			return ! ( preg_match( '/^\[([\w-]+)\]$/', $part, $m ) && in_array( $m[1], $safe, true ) );
		} );
		return $bad ? $value : '';
	}
	return $value;
}

/** Nombres de los campos de tipo email de una plantilla de formulario. */
function spiral_mcp_cf7_email_fields( $template ) {
	$names = array();
	foreach ( WPCF7_FormTagsManager::get_instance()->scan( (string) $template ) as $tag ) {
		if ( 'email' === $tag->basetype && '' !== $tag->name ) {
			$names[] = $tag->name;
		}
	}
	return $names;
}

/** Cambios en a quién llega el correo (destinatario, cabeceras To/Cc/Bcc, adjuntos) entre dos juegos de propiedades. */
function spiral_mcp_cf7_mail_changes( array $before, array $after ) {
	$changes = array();
	$fields  = spiral_mcp_cf7_email_fields( $after['form'] ?? ( $before['form'] ?? '' ) );
	foreach ( array( 'mail', 'mail_2' ) as $prop ) {
		if ( ! isset( $after[ $prop ] ) ) {
			continue;
		}
		if ( 'mail_2' === $prop && empty( $after[ $prop ]['active'] ) ) {
			continue; // El correo 2 desactivado no envía nada.
		}
		foreach ( spiral_mcp_cf7_sensitive_mail_keys() as $key ) {
			$old = spiral_mcp_cf7_mail_target( $before[ $prop ] ?? array(), $key, $fields );
			$new = spiral_mcp_cf7_mail_target( $after[ $prop ], $key, $fields );
			if ( 'mail_2' === $prop && empty( $before[ $prop ]['active'] ) ) {
				$old = ''; // Al activarlo, cuenta todo lo que no sea seguro.
			}
			if ( $old !== $new ) {
				$changes[] = array( 'field' => $prop . '.' . $key, 'from' => (string) ( $before[ $prop ][ $key ] ?? '' ), 'to' => (string) ( $after[ $prop ][ $key ] ?? '' ) );
			}
		}
	}
	return $changes;
}

/** Guarda un formulario con wpcf7_save_contact_form() y devuelve el objeto o WP_Error. */
function spiral_mcp_cf7_save( $id, array $data, $title = null, $locale = null ) {
	$args = array( 'id' => (int) $id );
	if ( null !== $title ) {
		$args['title'] = (string) $title;
	}
	if ( null !== $locale ) {
		$args['locale'] = (string) $locale;
	}
	$saved = wpcf7_save_contact_form( $args + $data );
	if ( ! $saved || ! $saved->id() ) {
		return new WP_Error( 'spiral_mcp_form_save', 'Contact Form 7 no ha guardado el formulario.' );
	}
	if ( isset( $data['mail_2']['active'] ) ) {
		// wpcf7_save_contact_form() no fuerza "active" en mail_2: se respeta lo pedido.
		$mail_2           = $saved->prop( 'mail_2' );
		$mail_2['active'] = (bool) $data['mail_2']['active'];
		$saved->set_properties( array( 'mail_2' => $mail_2 ) );
		$saved->save();
	}
	return wpcf7_contact_form( $saved->id() );
}

/* -------------------------------------------------------------------------
 * Snapshots: formulario completo (ref = ID)
 * ---------------------------------------------------------------------- */

add_filter( 'spiral_mcp_snapshot_kinds', function ( $kinds ) {
	$kinds['form'] = array(
		'group'   => 'content',
		'cap'     => 'wpcf7_edit_contact_forms',
		'capture' => function ( $ref ) {
			if ( ! spiral_mcp_cf7_active() ) {
				return null;
			}
			$form = wpcf7_contact_form( (int) $ref );
			return $form ? spiral_mcp_cf7_export( $form ) : null;
		},
		'restore' => function ( $ref, $data ) {
			if ( ! spiral_mcp_cf7_active() ) {
				return new WP_Error( 'spiral_mcp_forms_inactive', 'Contact Form 7 no está activo: no se puede restaurar el formulario.' );
			}
			$id     = (int) $ref;
			$exists = $id && wpcf7_contact_form( $id );
			if ( null === $data ) {
				// No existía: se borra.
				if ( $exists ) {
					wpcf7_contact_form( $id )->delete();
				}
				return array( 'id' => 0, 'deleted' => (bool) $exists );
			}
			if ( ! $exists ) {
				// Se recrea con el mismo ID y hash, para que los shortcodes de las páginas vuelvan a funcionar.
				$id = wp_insert_post( array(
					'import_id'   => $id,
					'post_type'   => 'wpcf7_contact_form',
					'post_status' => 'publish',
					'post_title'  => wp_slash( $data['title'] ),
				) );
				if ( ! $id || is_wp_error( $id ) ) {
					return new WP_Error( 'spiral_mcp_form_save', 'No se pudo recrear el formulario.' );
				}
				if ( ! empty( $data['hash'] ) ) {
					update_post_meta( $id, '_hash', $data['hash'] );
				}
			}
			$form = spiral_mcp_cf7_save( $id, (array) $data['properties'], $data['title'], $data['locale'] );
			if ( is_wp_error( $form ) ) {
				return $form;
			}
			return array( 'id' => $form->id(), 'shortcode' => $form->shortcode() );
		},
	);
	return $kinds;
} );

/* -------------------------------------------------------------------------
 * Categoría y abilities
 * ---------------------------------------------------------------------- */

add_action( 'wp_abilities_api_categories_init', function () {
	if ( ! spiral_mcp_cf7_active() ) {
		return;
	}
	wp_register_ability_category( 'spiral-mcp-forms', array(
		'label'       => 'Formularios Spiral-MCP',
		'description' => 'Formularios de Contact Form 7: campos, correo, mensajes y dónde se usan.',
	) );
} );

add_action( 'wp_abilities_api_init', function () {
	if ( ! spiral_mcp_cf7_active() ) {
		return;
	}

	$can_read = function () {
		return current_user_can( 'wpcf7_read_contact_forms' );
	};
	$can_edit = function () {
		return current_user_can( 'wpcf7_edit_contact_forms' );
	};
	$id_prop = array( 'type' => array( 'string', 'integer' ), 'description' => 'ID, hash del shortcode o título exacto del formulario.' );
	$mail_schema = array(
		'type'        => 'object',
		'description' => 'Solo las claves que cambian (se mezclan con las actuales): subject, sender, recipient, body, additional_headers, attachments, use_html, exclude_blank (y active en mail_2). Usa etiquetas de correo como [your-name] o [_site_admin_email].',
	);
	$write_props = array(
		'title'               => array( 'type' => 'string' ),
		'template'            => array( 'type' => 'string', 'description' => 'Plantilla del formulario (pestaña "Formulario" de CF7): HTML con etiquetas como [text* your-name], [email* your-email], [textarea your-message], [acceptance acepto], [submit "Enviar"]. Sustituye la plantilla entera.' ),
		'mail'                => $mail_schema,
		'mail_2'              => $mail_schema,
		'messages'            => array( 'type' => 'object', 'description' => 'Mensajes que ve el visitante, por clave (mail_sent_ok, mail_sent_ng, validation_error, spam, accept_terms, invalid_required…; get-form los lista). Solo las que cambian.' ),
		'additional_settings' => array( 'type' => 'string', 'description' => 'Ajustes adicionales, uno por línea (p. ej. "demo_mode: on", "skip_mail: on", "subscribers_only: true"). Sustituye el texto entero.' ),
		'confirm'             => array( 'type' => 'boolean', 'description' => 'true si cambias a quién llega el correo (recipient, additional_headers o attachments de mail, o de mail_2 activo), tras confirmarlo con el usuario.' ),
	);

	/* 1. Listar ------------------------------------------------------------ */
	spiral_mcp_register_ability( 'spiral-mcp/list-forms', array(
		'label'               => 'Listar formularios',
		'description'         => 'Lista los formularios de Contact Form 7: ID, hash, título, shortcode para insertarlo (p. ej. [contact-form-7 id="1a2b3c4" title="Contacto"]), idioma, número de campos ("field_count"), a quién llega el correo ("mail_to") y dónde se usa ("used_in": páginas, entradas y widgets que contienen su shortcode, también dentro de layouts de YOOtheme). Para ver o cambiar un formulario, spiral-mcp/get-form.',
		'category'            => 'spiral-mcp-forms',
		'group'               => 'content',
		'input_schema'        => array(
			'type'       => 'object',
			'properties' => array(
				'search' => array( 'type' => 'string', 'description' => 'Texto en el título.' ),
			),
		),
		'permission_callback' => $can_read,
		'execute_callback'    => function ( $input = array() ) {
			$input = (array) $input;
			$args  = array( 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC' );
			if ( ! empty( $input['search'] ) ) {
				$args['s'] = (string) $input['search'];
			}
			$usage = spiral_mcp_cf7_usage();
			$items = array();
			foreach ( WPCF7_ContactForm::find( $args ) as $form ) {
				$items[] = spiral_mcp_cf7_summary( $form, $usage );
			}
			return array(
				'plugin'  => 'contact-form-7',
				'version' => defined( 'WPCF7_VERSION' ) ? WPCF7_VERSION : '',
				'total'   => count( $items ),
				'forms'   => $items,
				'stores_submissions' => class_exists( 'Flamingo_Inbound_Message' ),
			);
		},
		'meta'                => spiral_mcp_meta( true, false, true ),
	) );

	/* 2. Leer -------------------------------------------------------------- */
	spiral_mcp_register_ability( 'spiral-mcp/get-form', array(
		'label'               => 'Leer un formulario',
		'description'         => 'Devuelve un formulario de Contact Form 7 completo: plantilla ("template", con sus etiquetas [tipo nombre opciones "valores"]), campos ya interpretados ("fields": tipo, nombre, obligatorio, opciones, valores), correo ("mail") y correo de respuesta al visitante ("mail_2"), mensajes ("messages"), ajustes adicionales, etiquetas de correo disponibles ("mail_tags", p. ej. [your-name]), problemas de configuración que detecta CF7 ("config_errors") y dónde se usa. Léelo antes de spiral-mcp/update-form.',
		'category'            => 'spiral-mcp-forms',
		'group'               => 'content',
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'id' ),
			'properties' => array( 'id' => $id_prop ),
		),
		'permission_callback' => $can_read,
		'execute_callback'    => function ( $input ) {
			$form = spiral_mcp_cf7_resolve( $input['id'] ?? '' );
			if ( is_wp_error( $form ) ) {
				return $form;
			}
			$props = $form->get_properties();
			$out   = spiral_mcp_cf7_summary( $form, spiral_mcp_cf7_usage() );
			$out  += array(
				'template'            => (string) $props['form'],
				'fields'              => spiral_mcp_cf7_fields( $form ),
				'mail'                => $props['mail'],
				'mail_2'              => $props['mail_2'],
				'messages'            => $props['messages'],
				'additional_settings' => (string) $props['additional_settings'],
				'mail_tags'           => array_values( $form->collect_mail_tags() ),
				'config_errors'       => spiral_mcp_cf7_config_errors( $form ),
			);
			$warnings = spiral_mcp_cf7_warnings( $form, array() );
			if ( $warnings ) {
				$out['warnings'] = $warnings;
			}
			return $out;
		},
		'meta'                => spiral_mcp_meta( true, false, true ),
	) );

	/* 3. Crear ------------------------------------------------------------- */
	spiral_mcp_register_ability( 'spiral-mcp/create-form', array(
		'label'               => 'Crear un formulario',
		'description'         => 'Crea un formulario de Contact Form 7. Sin "template", "mail" ni "messages" usa la plantilla por defecto de CF7 (nombre, email, asunto y mensaje; el correo llega al email de administración del sitio) en el idioma del sitio. Con "copy_from" (ID, hash o título) parte de una copia de otro formulario. Si pones un destinatario distinto del de la plantilla hace falta "confirm": true. Devuelve el shortcode: insértalo en una página (en YOOtheme, en un elemento "text" o "html" con spiral-mcp/patch-layout) y revisa la página con inspect-page. "config_errors" avisa de lo que CF7 ve mal (p. ej. remitente de otro dominio). restore-snapshot con el "snapshot_id" lo borra.',
		'category'            => 'spiral-mcp-forms',
		'group'               => 'content',
		'log'                 => array( 'type' => 'form', 'id' => 'spiral_mcp_cf7_log_id' ),
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'title' ),
			'properties' => $write_props + array(
				'copy_from' => $id_prop,
				'locale'    => array( 'type' => 'string', 'description' => 'Idioma de la plantilla por defecto (p. ej. es_ES). Por defecto, el del sitio.' ),
			),
		),
		'permission_callback' => $can_edit,
		'execute_callback'    => function ( $input ) {
			$input = (array) $input;
			if ( ! empty( $input['copy_from'] ) ) {
				$source = spiral_mcp_cf7_resolve( $input['copy_from'] );
				if ( is_wp_error( $source ) ) {
					return $source;
				}
				$base   = $source->get_properties();
				$locale = $source->locale();
			} else {
				$locale = ! empty( $input['locale'] ) ? (string) $input['locale'] : get_locale();
				$base   = WPCF7_ContactForm::get_template( array( 'locale' => $locale ) )->get_properties();
			}
			$data    = array_merge( array_intersect_key( $base, array_flip( spiral_mcp_cf7_props() ) ), spiral_mcp_cf7_merge_input( $input, $base ) );
			$changes = spiral_mcp_cf7_mail_changes( $base, $data );
			if ( $changes && true !== ( $input['confirm'] ?? false ) ) {
				return new WP_Error( 'spiral_mcp_form_mail_confirm', 'Cambias a quién llegan los mensajes del formulario. Confírmalo con el usuario y repite con "confirm": true.', array( 'changes' => $changes ) );
			}
			$form = spiral_mcp_cf7_save( -1, $data, (string) $input['title'], $locale );
			if ( is_wp_error( $form ) ) {
				return $form;
			}
			$snapshot = spiral_mcp_snapshot_take( 'form', $form->id(), null, 'create-form' );
			$out      = spiral_mcp_cf7_summary( $form ) + array(
				'config_errors' => spiral_mcp_cf7_config_errors( $form, true ),
				'snapshot_id'   => is_wp_error( $snapshot ) ? null : $snapshot,
			);
			$warnings = spiral_mcp_cf7_warnings( $form, $input );
			if ( $warnings ) {
				$out['warnings'] = $warnings;
			}
			return $out;
		},
		'meta'                => spiral_mcp_meta( false ),
	) );

	/* 4. Editar ------------------------------------------------------------ */
	spiral_mcp_register_ability( 'spiral-mcp/update-form', array(
		'label'               => 'Editar un formulario',
		'description'         => 'Cambia un formulario de Contact Form 7: título, plantilla ("template", entera), correo ("mail" y "mail_2": solo las claves que cambian), mensajes (solo los que cambian) o ajustes adicionales. Primero léelo con spiral-mcp/get-form. Si cambias a quién llega el correo (recipient, additional_headers con Cc/Bcc, o attachments; también activar mail_2) hace falta "confirm": true tras decírselo al usuario. Si cambias el nombre de un campo, actualiza también las etiquetas del correo que lo usan ([your-name]…). Guarda un snapshot antes ("snapshot_id"; restore-snapshot lo deshace) y devuelve "config_errors".',
		'category'            => 'spiral-mcp-forms',
		'group'               => 'content',
		'log'                 => array( 'type' => 'form', 'id' => 'spiral_mcp_cf7_log_id' ),
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'id' ),
			'properties' => array( 'id' => $id_prop ) + $write_props,
		),
		'permission_callback' => $can_edit,
		'execute_callback'    => function ( $input ) {
			$input = (array) $input;
			$form  = spiral_mcp_cf7_resolve( $input['id'] ?? '' );
			if ( is_wp_error( $form ) ) {
				return $form;
			}
			$current = $form->get_properties();
			$data    = spiral_mcp_cf7_merge_input( $input, $current );
			if ( ! $data && ! isset( $input['title'] ) ) {
				return new WP_Error( 'spiral_mcp_bad_input', 'No hay nada que cambiar: envía title, template, mail, mail_2, messages o additional_settings.' );
			}
			$changes = spiral_mcp_cf7_mail_changes( $current, $data );
			if ( $changes && true !== ( $input['confirm'] ?? false ) ) {
				return new WP_Error( 'spiral_mcp_form_mail_confirm', 'Cambias a quién llegan los mensajes del formulario. Confírmalo con el usuario y repite con "confirm": true.', array( 'changes' => $changes ) );
			}
			$snapshot = spiral_mcp_snapshot_capture( 'form', $form->id(), 'update-form' );
			if ( is_wp_error( $snapshot ) ) {
				return $snapshot;
			}
			$saved = spiral_mcp_cf7_save( $form->id(), $data, isset( $input['title'] ) ? (string) $input['title'] : null );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
			$out = spiral_mcp_cf7_summary( $saved ) + array(
				'changed'       => array_values( array_merge( array_keys( $data ), isset( $input['title'] ) ? array( 'title' ) : array() ) ),
				'config_errors' => spiral_mcp_cf7_config_errors( $saved, true ),
				'snapshot_id'   => $snapshot,
			);
			if ( $changes ) {
				$out['mail_changes'] = $changes;
			}
			$warnings = spiral_mcp_cf7_warnings( $saved, $input );
			if ( $warnings ) {
				$out['warnings'] = $warnings;
			}
			return $out;
		},
		'meta'                => spiral_mcp_meta( false, false, true ),
	) );

	/* 5. Borrar ------------------------------------------------------------ */
	spiral_mcp_register_ability( 'spiral-mcp/delete-form', array(
		'label'               => 'Borrar un formulario',
		'description'         => 'Borra un formulario de Contact Form 7 (CF7 no tiene papelera). Exige "confirm": true. Si el formulario se usa en alguna página o widget ("used_in" en list-forms), allí quedará un aviso de formulario no encontrado: quita antes el shortcode o avisa al usuario. Guarda un snapshot ("snapshot_id"): restore-snapshot lo recrea con el mismo ID y shortcode.',
		'category'            => 'spiral-mcp-forms',
		'group'               => 'content',
		'log'                 => array( 'type' => 'form', 'id' => 'spiral_mcp_cf7_log_id' ),
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'id', 'confirm' ),
			'properties' => array(
				'id'      => $id_prop,
				'confirm' => array( 'type' => 'boolean', 'description' => 'Debe ser true.' ),
			),
		),
		'permission_callback' => function () {
			return current_user_can( 'wpcf7_delete_contact_forms' );
		},
		'execute_callback'    => function ( $input ) {
			$form = spiral_mcp_cf7_resolve( $input['id'] ?? '' );
			if ( is_wp_error( $form ) ) {
				return $form;
			}
			$confirm = spiral_mcp_require_confirm( $input, 'borrar el formulario' );
			if ( is_wp_error( $confirm ) ) {
				return $confirm;
			}
			$usage    = spiral_mcp_cf7_usage();
			$used_in  = $usage[ $form->id() ] ?? array();
			$snapshot = spiral_mcp_snapshot_capture( 'form', $form->id(), 'delete-form' );
			if ( is_wp_error( $snapshot ) ) {
				return $snapshot;
			}
			$id    = $form->id();
			$title = $form->title();
			if ( ! $form->delete() ) {
				return new WP_Error( 'spiral_mcp_form_delete', 'Contact Form 7 no ha borrado el formulario.' );
			}
			$out = array(
				'deleted'     => true,
				'id'          => $id,
				'title'       => $title,
				'snapshot_id' => $snapshot,
			);
			if ( $used_in ) {
				$out['used_in']  = $used_in;
				$out['warnings'] = array( 'El formulario se usaba en estas páginas o widgets: ahora muestran un aviso. Quita el shortcode o restaura el snapshot.' );
			}
			return $out;
		},
		'meta'                => spiral_mcp_meta( false, true, false ),
	) );
} );
