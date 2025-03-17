<?php

add_shortcode('modifier_palette_dynamique', 'formulaire_palette_dynamique');
function formulaire_palette_dynamique() {
    // Ensure the WP_Theme_JSON_Resolver class is available
    if (!class_exists('WP_Theme_JSON_Resolver')) {
        return '<p>Cette fonctionnalité nécessite WordPress 5.9 ou supérieur.</p>';
    }

    // Récupérer les données complètes
    $user_settings = WP_Theme_JSON_Resolver::get_user_data()->get_settings();
    $theme_settings = WP_Theme_JSON_Resolver::get_theme_data()->get_settings();
    
    // Essayer différentes structures possibles pour la palette
    $user_palette = [];
    if (isset($user_settings['color']['palette']['theme'])) {
        $user_palette = $user_settings['color']['palette']['theme'];
    } elseif (isset($user_settings['color']['palette'])) {
        $user_palette = $user_settings['color']['palette'];
    }
    
    $theme_palette = [];
    if (isset($theme_settings['color']['palette']['theme'])) {
        $theme_palette = $theme_settings['color']['palette']['theme'];
    } elseif (isset($theme_settings['color']['palette'])) {
        $theme_palette = $theme_settings['color']['palette'];
    }

    // Quelle palette afficher ?
    $palette = !empty($user_palette) ? $user_palette : $theme_palette;



    // Traitement du formulaire : enregistrement des nouvelles couleurs
    if (isset($_POST['enregistrer_palette'])) {
        $nouvelles_couleurs = [];

        foreach ($palette as $index => $couleur) {
            // Déterminer le slug à utiliser
            $slug = isset($couleur['slug']) ? $couleur['slug'] : 'color-' . $index;
            
            // Déterminer la valeur de couleur à utiliser
            $nouvelle_valeur = sanitize_hex_color($_POST['palette_' . $slug] ?? ($couleur['color'] ?? '#000000'));
            
            // Construire l'entrée de couleur
            $nouvelles_couleurs[] = [
                'slug'  => $slug,
                'name'  => $couleur['name'] ?? ('Couleur ' . $index),
                'color' => $nouvelle_valeur
            ];
        }

        $new_data = [
            'version' => 2,
            'settings' => [
                'color' => [
                    'palette' => $nouvelles_couleurs
                ]
            ]
        ];

        // Use WP_Query to find the global styles post instead of wp_get_global_styles_post()
        if (post_type_exists('wp_global_styles')) {
            $global_styles_query = new WP_Query(
                [
                    'post_type' => 'wp_global_styles',
                    'post_status' => 'publish',
                    'posts_per_page' => 1,
                    'no_found_rows' => true,
                    'fields' => 'ids',
                ]
            );
            
            $global_styles_post_id = $global_styles_query->posts ? $global_styles_query->posts[0] : 0;
            
            if ($global_styles_post_id) {
                wp_update_post([
                    'ID' => $global_styles_post_id,
                    'post_content' => wp_json_encode($new_data),
                ]);
            } else {
                wp_insert_post([
                    'post_title'   => 'Custom Styles',
                    'post_name'    => 'wp-global-styles',
                    'post_type'    => 'wp_global_styles',
                    'post_status'  => 'publish',
                    'post_content' => wp_json_encode($new_data),
                    'post_author'  => get_current_user_id(),
                    'meta_input'   => [
                        'is_user_theme' => true,
                    ]
                ]);
            }
        } else {
            // Fallback method: store in options table
            update_option('custom_theme_palette', $nouvelles_couleurs);
        }

        echo '<p>Palette mise à jour avec succès.</p>';
        echo '<script>location.reload();</script>';
    }

    // Réinitialisation de la palette
    if (isset($_POST['reinitialiser_palette'])) {
        // Try to find and delete the global styles post
        if (post_type_exists('wp_global_styles')) {
            $global_styles_query = new WP_Query(
                [
                    'post_type' => 'wp_global_styles',
                    'post_status' => 'publish',
                    'posts_per_page' => 1,
                    'no_found_rows' => true,
                    'fields' => 'ids',
                ]
            );
            
            $global_styles_post_id = $global_styles_query->posts ? $global_styles_query->posts[0] : 0;
            
            if ($global_styles_post_id) {
                wp_delete_post($global_styles_post_id, true);
            }
        } else {
            // Fallback: delete from options
            delete_option('custom_theme_palette');
        }
        
        echo '<p>Palette réinitialisée.</p>';
        echo '<script>location.reload();</script>';
    }

    // Affichage du formulaire
    ob_start();
    ?>
    <form method="post" id="form-palette">
        <h3>Modifier dynamiquement la palette du thème</h3>
        <div class="palette-mosaique">
            <?php foreach ($palette as $index => $couleur): ?>
                <div class="mosaique-couleur" 
                    style="background-color: <?php echo isset($couleur['color']) ? esc_attr($couleur['color']) : '#000000'; ?>;" 
                    title="<?php echo isset($couleur['name']) ? esc_attr($couleur['name']) : 'Couleur ' . $index; ?>">
                </div>
            <?php endforeach; ?>
        </div>

        <div class="palette-container">
            <?php foreach ($palette as $index => $couleur): ?>
                <div class="palette-item">
                    <label>
                        <?php echo isset($couleur['name']) ? esc_html($couleur['name']) : 'Couleur ' . $index; ?>
                        <input 
                            type="color" 
                            name="palette_<?php echo isset($couleur['slug']) ? esc_attr($couleur['slug']) : 'color-' . $index; ?>" 
                            value="<?php echo isset($couleur['color']) ? esc_attr($couleur['color']) : '#000000'; ?>" 
                            data-css-var="--wp--preset--color--<?php echo isset($couleur['slug']) ? esc_attr($couleur['slug']) : 'color-' . $index; ?>"
                            class="live-color"
                        >
                    </label>
                    <span class="code-var">var(--wp--preset--color--<?php echo isset($couleur['slug']) ? esc_attr($couleur['slug']) : 'color-' . $index; ?>)</span>
                </div>
            <?php endforeach; ?>
        </div>

        <div style="margin-top: 20px;">
            <input type="submit" name="enregistrer_palette" value="Enregistrer les couleurs">
            <input type="submit" name="reinitialiser_palette" value="Réinitialiser la palette">
        </div>
    </form>

    <style>
        :root {
            <?php foreach ($palette as $index => $couleur): ?>
                <?php if (isset($couleur['slug']) && isset($couleur['color'])): ?>
                --wp--preset--color--<?php echo esc_attr($couleur['slug']); ?>: <?php echo esc_attr($couleur['color']); ?>;
                <?php else: ?>
                --wp--preset--color--color-<?php echo $index; ?>: <?php echo isset($couleur['color']) ? esc_attr($couleur['color']) : '#000000'; ?>;
                <?php endif; ?>
            <?php endforeach; ?>
        }
        .palette-container { margin-top: 20px; }
        .palette-item { margin-bottom: 12px; }
        .palette-item label { display: flex; align-items: center; gap: 12px; }
        .code-var { font-family: monospace; color: #666; font-size: 12px; }
        .palette-mosaique { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 20px; }
        .mosaique-couleur { width: 30px; height: 30px; border-radius: 4px; border: 1px solid #ddd; cursor: default; }
    </style>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const inputs = document.querySelectorAll('.live-color');
            inputs.forEach(input => {
                input.addEventListener('input', function () {
                    const cssVar = this.dataset.cssVar;
                    document.documentElement.style.setProperty(cssVar, this.value);
                    // Met à jour la mosaïque
                    const index = Array.from(inputs).indexOf(this);
                    const tile = document.querySelectorAll('.mosaique-couleur')[index];
                    if (tile) tile.style.backgroundColor = this.value;
                });
            });
        });
    </script>
    <?php
    return ob_get_clean();
}
