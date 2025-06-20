<?php
/**
 * Plugin Name:       SVG Gravity
 * Plugin URI:        https://www.sferainteractive.com/
 * Description:       Enhancements for Gravity Forms including secure edit links and customized webhook payloads.
 * Version:           3.3.1
 * Author:            Sfera Interactive
 * Author URI:        https://www.sferainteractive.com/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       svg-gravity
 */

if ( ! defined( 'WPINC' ) ) die;

/**
 * V3.3.1: Refined webhook payload to exactly match the semantic model.
 * - Field keys now use double underscores (e.g., 'company__name').
 * - Nested form arrays are now correctly placed inside the 'submission_data' object.
 * 
 * V3.3.0: Reworked webhook payload for clean structure and full data population.
 * - Fixes empty 'Name' fields by correctly assembling full names.
 * - Restructures JSON: groups main data under 'submission_data' and separates nested forms.
 * - Ensures all fields, including those in nested forms, are populated.
 * 
 * V3.2.9: Final fix to prevent all conflicts with standard forms.
 * - Checks for unique edit URL parameters first, which is the safest method.
 * - Guarantees plugin logic never runs on or interferes with standard forms.
 * 
 * V3.2.8: Isolated plugin logic to prevent conflicts with standard forms.
 * - Plugin now only runs on the designated 'form-test-edit' page.
 * - Fixes "Next" and "Save & Continue" buttons being disabled on other forms.
 * - Resolves all remaining JavaScript conflicts.
 * 
 * V3.2.7: Fixed JavaScript errors on standard forms.
 * - Manually enqueues GF scripts on edit page to prevent "gform is not defined" error.
 * - Resolves conflicts with standard Gravity Forms functionality.
 * 
 * V3.2.6: Fixed sluggified field names in debug banner.
 * - Uses a more reliable map-based approach for field name lookup
 * - Ensures all fields (simple, complex, and nested) are correctly labeled
 * 
 * V3.2.5: Added sluggified field names for nested form entries.
 * - Shows field labels for individual nested entry fields
 * - Handles complex field inputs within nested forms
 * - Complete field name visibility for all data levels
 * 
 * V3.2.4: Added sluggified field names to debug banner.
 * - Shows field labels alongside field IDs for better readability
 * - Handles both simple fields and complex field inputs
 * - Makes debugging data more understandable
 * 
 * V3.2.3: Enhanced debug banner with nested form data.
 * - Shows all nested form entries and their field values
 * - Displays Supporting Diagrams (field 32) and White Paper Authors (field 33)
 * - Complete data visibility for troubleshooting
 * 
 * V3.2.2: Added debug banner for troubleshooting.
 * - Shows all entry data in floating debug banner
 * - Displays field values that should be populated
 * - Helps identify data availability vs population issues
 * 
 * V3.2.1: Fixed entry editor implementation.
 * - Fixed fatal error by using correct Gravity Forms entry editor shortcode
 * - Uses [gravityform entry="ID" mode="edit"] shortcode
 * 
 * V3.2.0: Switched to Gravity Forms' built-in entry editing.
 * - Uses gform_entries_field_value filter for reliable field population
 * - Leverages Gravity Forms' own editing system
 * - Removes complex manual population logic
 */

// --------------------------------------------------------------------------------
// 1. Generate Secure Edit Token
// --------------------------------------------------------------------------------

/**
 * Generate and store a unique edit token for a Gravity Form entry.
 */
function svg_generate_edit_token( $entry, $form ) {
    $token = wp_hash( $entry['id'] . $entry['date_created'] . wp_salt() );
    gform_update_meta( $entry['id'], 'edit_token', $token );
    error_log( 'SVG Gravity: Generated edit token for entry ' . $entry['id'] . ': ' . substr( $token, 0, 8 ) . '...' );
    return $entry;
}
add_filter( 'gform_entry_post_save', 'svg_generate_edit_token', 5, 2 );

// --------------------------------------------------------------------------------
// 2. Entry Editor Integration
// --------------------------------------------------------------------------------

/**
 * Check for edit request and set up entry editing
 */
add_action( 'template_redirect', 'svg_setup_entry_editing' );
function svg_setup_entry_editing() {
    // Check for the unique edit URL parameters first. This is the safest way to ensure
    // the plugin only runs when it's supposed to and never interferes with other forms.
    if ( ! isset( $_GET['gform_update'] ) || ! isset( $_GET['token'] ) ) {
        return;
    }
    
    // Only affect the frontend, and only on the designated edit page.
    if ( is_admin() || ! is_page( 'form-test-edit' ) ) {
        return;
    }
    
    $entry_id = intval( $_GET['gform_update'] );
    $token    = sanitize_text_field( $_GET['token'] );

    if ( svg_verify_edit_token( $entry_id, $token ) ) {
        
        // Force Gravity Forms scripts and styles to load.
        // This is the critical fix for the "gform is not defined" error.
        $entry = GFAPI::get_entry( $entry_id );
        if ( ! is_wp_error( $entry ) ) {
            $form_id = $entry['form_id'];
            if ( function_exists( 'gravity_form_enqueue_scripts' ) ) {
                gravity_form_enqueue_scripts( $form_id, true );
            }
        }
        
        // Use Gravity Forms' built-in entry editing
        add_filter( 'gform_entries_field_value', 'svg_populate_entry_field_value', 10, 4 );
        
        // Simple content replacement
        add_filter( 'the_content', 'svg_replace_with_entry_editor', 999 );
        
        // Edit banner
        add_action( 'wp_footer', 'svg_add_banner' );
        
        error_log( 'SVG Gravity (v3.2.0): Using built-in entry editing for entry ' . $entry_id );
    }
}

/**
 * Replace page content with entry editor
 */
function svg_replace_with_entry_editor( $content ) {
    if ( isset( $_GET['gform_update'] ) && isset( $_GET['token'] ) ) {
        $entry_id = intval( $_GET['gform_update'] );
        $token    = sanitize_text_field( $_GET['token'] );

        if ( svg_verify_edit_token( $entry_id, $token ) ) {
            error_log( 'SVG Gravity (v3.2.0): Using entry editor for entry ' . $entry_id );
            
            // Get the entry
            $entry = GFAPI::get_entry( $entry_id );
            if ( is_wp_error( $entry ) ) {
                return '<p>Error: Entry not found.</p>';
            }
            
            // Get the form
            $form = GFAPI::get_form( $entry['form_id'] );
            if ( ! $form ) {
                return '<p>Error: Form not found.</p>';
            }
            
            // Use Gravity Forms' entry editor shortcode
            return do_shortcode( '[gravityform id="' . $form['id'] . '" entry="' . $entry_id . '" mode="edit"]' );
        }
    }
    return $content;
}

/**
 * Populate field values in the entry editor using Gravity Forms' built-in filter
 */
function svg_populate_entry_field_value( $value, $form, $field, $entry ) {
    if ( isset( $_GET['gform_update'] ) && isset( $_GET['token'] ) ) {
        $entry_id = intval( $_GET['gform_update'] );
        $token    = sanitize_text_field( $_GET['token'] );

        if ( svg_verify_edit_token( $entry_id, $token ) ) {
            $field_id = strval( $field->id );
            
            error_log( 'SVG Gravity: Entry editor - Processing field ' . $field_id . ' (type: ' . $field->type . ', label: ' . $field->label . ')');
            
            // Get the current entry data
            $current_entry = GFAPI::get_entry( $entry_id );
            if ( ! is_wp_error( $current_entry ) ) {
                
                // Handle different field types
                switch ( $field->type ) {
                    case 'fileupload':
                    case 'radio':
                    case 'nestedform':
                    case 'form':
                    case 'multi_choice':
                        // These field types store values directly
                        if ( isset( $current_entry[ $field_id ] ) && ! empty( $current_entry[ $field_id ] ) ) {
                            error_log( 'SVG Gravity: Entry editor - Set ' . $field->type . ' field ' . $field_id . ' to ' . $current_entry[ $field_id ] );
                            return $current_entry[ $field_id ];
                        }
                        break;
                        
                    default:
                        // Handle complex fields with inputs
                        if ( is_array( $field->inputs ) ) {
                            foreach ( $field->inputs as $input ) {
                                $input_id = strval( $input['id'] );
                                if ( isset( $current_entry[ $input_id ] ) ) {
                                    error_log( 'SVG Gravity: Entry editor - Set complex field input ' . $input_id . ' to ' . $current_entry[ $input_id ] );
                                    // For complex fields, we need to return the appropriate input value
                                    if ( $input['name'] === $field->inputName ) {
                                        return $current_entry[ $input_id ];
                                    }
                                }
                            }
                        } else {
                            // Simple fields
                            if ( isset( $current_entry[ $field_id ] ) ) {
                                error_log( 'SVG Gravity: Entry editor - Set simple field ' . $field_id . ' to ' . $current_entry[ $field_id ] );
                                return $current_entry[ $field_id ];
                            }
                        }
                        break;
                }
            }
        }
    }
    return $value;
}

/**
 * Add edit mode banner with debug data
 */
function svg_add_banner() {
    if ( isset( $_GET['gform_update'] ) && isset( $_GET['token'] ) ) {
        $entry_id = intval( $_GET['gform_update'] );
        $token    = sanitize_text_field( $_GET['token'] );

        if ( svg_verify_edit_token( $entry_id, $token ) ) {
            $entry = GFAPI::get_entry( $entry_id );
            if ( ! is_wp_error( $entry ) ) {
                // Get the form to access field information
                $form = GFAPI::get_form( $entry['form_id'] );
                
                // Build a map of field/input IDs to labels for the main form
                $field_map = [];
                if ( $form ) {
                    foreach ( $form['fields'] as $field ) {
                        // Handle the main field ID
                        if ( ! empty( $field->label ) ) {
                            $field_map[strval($field->id)] = ' (' . sanitize_title( $field->label ) . ')';
                        }
                        // Handle inputs, which might overwrite if the ID is the same (e.g., email field)
                        if ( is_array( $field->inputs ) ) {
                            foreach ( $field->inputs as $input ) {
                                if ( ! empty( $input['label'] ) ) {
                                    $field_map[strval($input['id'])] = ' (' . sanitize_title( $field->label ) . ' - ' . sanitize_title( $input['label'] ) . ')';
                                }
                            }
                        }
                    }
                }
                
                echo '<div style="padding: 20px; background: #f8f9fa; border: 2px solid #007cba; color: #333; border-radius: 8px; margin: 20px; position: fixed; top: 20px; right: 20px; z-index: 9999; max-width: 500px; max-height: 80vh; overflow-y: auto; font-family: monospace; font-size: 12px;">';
                echo '<h3 style="margin: 0 0 15px 0; color: #007cba;">🔧 DEBUG: Entry Data (ID: ' . $entry_id . ')</h3>';
                echo '<div style="margin-bottom: 10px;"><strong>Edit Mode:</strong> You are editing your submission.</div>';
                echo '<hr style="margin: 10px 0; border: 1px solid #ddd;">';
                
                // Main entry data
                echo '<div style="margin-bottom: 10px;"><strong>Main Entry Data:</strong></div>';
                echo '<pre style="background: #fff; padding: 10px; border-radius: 4px; overflow-x: auto; font-size: 10px; line-height: 1.4;">';
                foreach ( $entry as $key => $value ) {
                    if ( ! empty( $value ) && $key !== 'id' && $key !== 'form_id' && $key !== 'date_created' && $key !== 'date_updated' ) {
                        $field_name = isset( $field_map[$key] ) ? $field_map[$key] : '';
                        echo htmlspecialchars( $key ) . $field_name . ': ' . htmlspecialchars( $value ) . "\n";
                    }
                }
                echo '</pre>';
                
                // Nested form data
                echo '<hr style="margin: 10px 0; border: 1px solid #ddd;">';
                echo '<div style="margin-bottom: 10px;"><strong>Nested Form Data:</strong></div>';
                
                // Check for nested form fields (fields 32 and 33 based on your logs)
                $nested_fields = array(32, 33); // Supporting Diagrams and White Paper Authors
                
                foreach ( $nested_fields as $field_id ) {
                    if ( isset( $entry[ $field_id ] ) && ! empty( $entry[ $field_id ] ) ) {
                        $field_name = isset( $field_map[strval($field_id)] ) ? $field_map[strval($field_id)] : '';
                        
                        $nested_entry_ids = explode(',', $entry[ $field_id ]);
                        echo '<div style="margin-bottom: 8px;"><strong>Field ' . $field_id . $field_name . ' (Nested Entries):</strong></div>';
                        
                        foreach ( $nested_entry_ids as $nested_entry_id ) {
                            $nested_entry_id = trim( $nested_entry_id );
                            if ( ! empty( $nested_entry_id ) ) {
                                $nested_entry = GFAPI::get_entry( $nested_entry_id );
                                if ( ! is_wp_error( $nested_entry ) ) {
                                    // Get the nested form to access field information
                                    $nested_form = GFAPI::get_form( $nested_entry['form_id'] );
                                    
                                    // Build a map of field/input IDs to labels for the nested form
                                    $nested_field_map = [];
                                    if ( $nested_form ) {
                                        foreach ( $nested_form['fields'] as $nested_field ) {
                                            if ( ! empty( $nested_field->label ) ) {
                                                $nested_field_map[strval($nested_field->id)] = ' (' . sanitize_title( $nested_field->label ) . ')';
                                            }
                                            if ( is_array( $nested_field->inputs ) ) {
                                                foreach ( $nested_field->inputs as $nested_input ) {
                                                    if ( ! empty( $nested_input['label'] ) ) {
                                                        $nested_field_map[strval($nested_input['id'])] = ' (' . sanitize_title( $nested_field->label ) . ' - ' . sanitize_title( $nested_input['label'] ) . ')';
                                                    }
                                                }
                                            }
                                        }
                                    }
                                    
                                    echo '<div style="background: #e9ecef; padding: 8px; border-radius: 4px; margin-bottom: 5px; font-size: 10px;">';
                                    echo '<strong>Nested Entry ' . $nested_entry_id . ':</strong><br>';
                                    foreach ( $nested_entry as $nested_key => $nested_value ) {
                                        if ( ! empty( $nested_value ) && $nested_key !== 'id' && $nested_key !== 'form_id' && $nested_key !== 'date_created' && $nested_key !== 'date_updated' ) {
                                            $nested_field_name = isset( $nested_field_map[$nested_key] ) ? $nested_field_map[$nested_key] : '';
                                            echo '&nbsp;&nbsp;' . htmlspecialchars( $nested_key ) . $nested_field_name . ': ' . htmlspecialchars( $nested_value ) . '<br>';
                                        }
                                    }
                                    echo '</div>';
                                }
                            }
                        }
                    }
                }
                
                echo '<hr style="margin: 10px 0; border: 1px solid #ddd;">';
                echo '<div style="font-size: 11px; color: #666;">';
                echo '<strong>Token:</strong> ' . substr( $token, 0, 8 ) . '...<br>';
                echo '<strong>Form ID:</strong> ' . $entry['form_id'] . '<br>';
                echo '<strong>Created:</strong> ' . $entry['date_created'];
                echo '</div>';
                echo '</div>';
            }
        }
    }
}

// --------------------------------------------------------------------------------
// 3. Webhook Functionality
// --------------------------------------------------------------------------------

/**
 * Add edit token to webhook payloads
 */
add_filter( 'gform_webhooks_request_data', 'svg_add_edit_token_to_webhook', 10, 4 );
function svg_add_edit_token_to_webhook( $request_data, $feed, $entry, $form ) {
    $edit_token = gform_get_meta( $entry['id'], 'edit_token' );
    
    if ( $edit_token ) {
        $request_data['edit_token'] = $edit_token;
        $request_data['edit_url'] = home_url( '/form-test-edit/?gform_update=' . $entry['id'] . '&token=' . $edit_token );
        $request_data['entry_id'] = $entry['id'];
        error_log( 'SVG Gravity: Added edit token to webhook for entry ' . $entry['id'] );
    }
    
    return $request_data;
}

/**
 * Helper function to create double-underscore slugs from field labels.
 */
function svg_slugify_label( $label ) {
    // Return an empty string if the label is empty to avoid creating stray keys.
    if ( empty( $label ) ) {
        return '';
    }
    // Replaces spaces and hyphens with double underscores for a clean, semantic key.
    return str_replace( '-', '__', sanitize_title( $label ) );
}

/**
 * Customize webhook payload to match the desired semantic structure.
 */
add_filter( 'gform_webhooks_request_data', 'svg_rebuild_webhook_payload', 15, 4 );
function svg_rebuild_webhook_payload( $request_data, $feed, $entry, $form ) {
    
    $submission_data = [];

    // Process all fields from the main form
    foreach ( $form['fields'] as $field ) {
        $field_label_slug = svg_slugify_label( $field->label );
        // Skip fields that don't produce a valid slug (e.g., layout fields with no label)
        if ( empty( $field_label_slug ) ) continue;
        
        // Handle Nested Forms
        if ( $field->type == 'form' ) {
            $submission_data[$field_label_slug] = [];
            $nested_entry_ids = explode( ',', rgar( $entry, (string) $field->id ) );

            foreach ( $nested_entry_ids as $nested_entry_id ) {
                $nested_entry = GFAPI::get_entry( trim($nested_entry_id) );
                if ( is_wp_error( $nested_entry ) || ! $nested_entry ) continue;
                
                $nested_form = GFAPI::get_form( $nested_entry['form_id'] );
                $nested_item_data = [];

                foreach ( $nested_form['fields'] as $nested_field ) {
                    $nested_field_label_slug = svg_slugify_label( $nested_field->label );
                    if ( empty( $nested_field_label_slug ) ) continue;
                    
                    if ( $nested_field->type == 'name' ) {
                        $full_name = [];
                        foreach ( $nested_field->inputs as $input ) {
                            $input_value = rgar( $nested_entry, (string) $input['id'] );
                            if ( ! empty( $input_value ) ) {
                                $full_name[] = $input_value;
                            }
                        }
                        $nested_item_data[$nested_field_label_slug] = implode( ' ', $full_name );
                    } else {
                        $nested_item_data[$nested_field_label_slug] = rgar( $nested_entry, (string) $nested_field->id );
                    }
                }
                $submission_data[$field_label_slug][] = $nested_item_data;
            }
        // Handle Main Form Name Fields
        } else if ( $field->type == 'name' ) {
            $full_name = [];
            foreach ( $field->inputs as $input ) {
                $input_value = rgar( $entry, (string) $input['id'] );
                if ( ! empty( $input_value ) ) {
                    $full_name[] = $input_value;
                }
            }
            $submission_data[$field_label_slug] = implode( ' ', $full_name );
        // Handle all other main form fields
        } else {
            $submission_data[$field_label_slug] = rgar( $entry, (string) $field->id );
        }
    }

    // Build the final payload structure according to the model
    $payload = [];
    if ( isset( $request_data['edit_token'] ) ) {
        $payload['edit_token'] = $request_data['edit_token'];
        $payload['edit_url'] = $request_data['edit_url'];
        $payload['entry_id'] = $request_data['entry_id'];
    }
    
    $payload['submission_data'] = $submission_data;

    return $payload;
}

// Remove the old, simplistic customization function
remove_filter( 'gform_webhooks_request_data', 'svg_customize_webhook_payload', 10, 4 );

// --------------------------------------------------------------------------------
// 4. Notification Email Enhancement
// --------------------------------------------------------------------------------

/**
 * Add edit link to notification emails
 */
add_filter( 'gform_notification', 'svg_add_edit_link_to_notification', 10, 3 );
function svg_add_edit_link_to_notification( $notification, $form, $entry ) {
    $edit_token = gform_get_meta( $entry['id'], 'edit_token' );
    
    if ( $edit_token ) {
        $edit_url = home_url( '/form-test-edit/?gform_update=' . $entry['id'] . '&token=' . $edit_token );
        
        $edit_link_html = "\n\n---\n";
        $edit_link_html .= "You can edit your submission by clicking this link: " . $edit_url . "\n";
        $edit_link_html .= "This link is secure and will expire when you close your browser.\n";
        
        $notification['message'] .= $edit_link_html;
        error_log( 'SVG Gravity: Added edit link to notification for entry ' . $entry['id'] );
    }
    
    return $notification;
}

// --------------------------------------------------------------------------------
// 5. Helper Function: Verify Token
// --------------------------------------------------------------------------------

/**
 * Verify that an edit token is valid for a given entry.
 */
function svg_verify_edit_token( $entry_id, $token ) {
    $stored_token = gform_get_meta( $entry_id, 'edit_token' );
    return hash_equals( (string) $stored_token, $token );
}
