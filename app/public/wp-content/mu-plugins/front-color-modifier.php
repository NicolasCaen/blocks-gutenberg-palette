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
        
        <div class="palette-container">
            <?php foreach ($palette as $index => $couleur): ?>
                <div class="color-square-container">
                    <input 
                        type="color" 
                        name="palette_<?php echo isset($couleur['slug']) ? esc_attr($couleur['slug']) : 'color-' . $index; ?>" 
                        value="<?php echo isset($couleur['color']) ? esc_attr($couleur['color']) : '#000000'; ?>" 
                        data-css-var="--wp--preset--color--<?php echo isset($couleur['slug']) ? esc_attr($couleur['slug']) : 'color-' . $index; ?>"
                        class="live-color"
                        title="<?php echo isset($couleur['name']) ? esc_attr($couleur['name']) : 'Couleur ' . $index; ?> - var(--wp--preset--color--<?php echo isset($couleur['slug']) ? esc_attr($couleur['slug']) : 'color-' . $index; ?>)"
                    >
                </div>
            <?php endforeach; ?>
        </div>

        <div class="action-buttons">
            <input type="submit" name="enregistrer_palette" value="Enregistrer les couleurs" class="button button-primary">
            <input type="submit" name="reinitialiser_palette" value="Réinitialiser la palette" class="button">
            <button type="button" id="export-json" class="button">Exporter en JSON</button>
            <button type="button" id="import-json-btn" class="button">Importer JSON</button>
        </div>
        
        <div id="import-json-container" style="display: none; margin-top: 20px;">
            <textarea id="import-json-textarea" placeholder="Collez votre JSON de palette ici..." rows="5" style="width: 100%;"></textarea>
            <button type="button" id="apply-json" class="button button-primary" style="margin-top: 10px;">Appliquer</button>
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
        .palette-container { 
            display: flex; 
            flex-wrap: wrap; 
            gap: 10px; 
            margin: 20px 0; 
        }
        .color-square-container { 
            position: relative; 
        }
        .color-square-container input[type="color"] { 
            width: 50px; 
            height: 50px; 
            padding: 0; 
            border: none; 
            border-radius: 4px; 
            cursor: pointer; 
            box-shadow: 0 1px 3px rgba(0,0,0,0.1); 
        }
        .color-square-container input[type="color"]::-webkit-color-swatch-wrapper { 
            padding: 0; 
        }
        .color-square-container input[type="color"]::-webkit-color-swatch { 
            border: none; 
            border-radius: 4px; 
        }
        .action-buttons { 
            margin-top: 20px; 
            display: flex; 
            gap: 10px; 
        }
        #json-output { 
            display: none; 
            margin-top: 20px; 
            padding: 15px; 
            background-color: #f5f5f5; 
            border-radius: 4px; 
            font-family: monospace; 
            white-space: pre-wrap; 
            max-height: 200px; 
            overflow-y: auto; 
        }
    </style>

    <div id="json-output"></div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const inputs = document.querySelectorAll('.live-color');
            const exportButton = document.getElementById('export-json');
            const jsonOutput = document.getElementById('json-output');
            
            // Mise à jour des couleurs en temps réel
            inputs.forEach(input => {
                input.addEventListener('input', function () {
                    const cssVar = this.dataset.cssVar;
                    document.documentElement.style.setProperty(cssVar, this.value);
                });
            });
            
            // Gestion de l'importation JSON
            const importJsonBtn = document.getElementById('import-json-btn');
            const importJsonContainer = document.getElementById('import-json-container');
            const importJsonTextarea = document.getElementById('import-json-textarea');
            const applyJsonBtn = document.getElementById('apply-json');
            
            importJsonBtn.addEventListener('click', function() {
                importJsonContainer.style.display = importJsonContainer.style.display === 'none' ? 'block' : 'none';
            });
            
            applyJsonBtn.addEventListener('click', function() {
                try {
                    const importedData = JSON.parse(importJsonTextarea.value);
                    
                    if (!Array.isArray(importedData)) {
                        throw new Error('Le format JSON n\'est pas valide. Il doit s\'agir d\'un tableau d\'objets.');
                    }
                    
                    // Vérifier si nous avons suffisamment d'inputs pour les données importées
                    if (importedData.length > inputs.length) {
                        alert(`Attention : La palette importée contient ${importedData.length} couleurs, mais vous n'avez que ${inputs.length} emplacements disponibles. Seules les ${inputs.length} premières couleurs seront utilisées.`);
                    }
                    
                    // Appliquer les couleurs aux inputs existants
                    for (let i = 0; i < Math.min(importedData.length, inputs.length); i++) {
                        const colorData = importedData[i];
                        const input = inputs[i];
                        
                        if (colorData.color) {
                            input.value = colorData.color;
                            const cssVar = input.dataset.cssVar;
                            document.documentElement.style.setProperty(cssVar, colorData.color);
                        }
                    }
                    
                    alert('Palette importée avec succès ! Cliquez sur "Enregistrer les couleurs" pour appliquer les changements.');
                    importJsonContainer.style.display = 'none';
                    
                } catch (error) {
                    alert('Erreur lors de l\'importation : ' + error.message);
                }
            });
            
            // Exportation JSON
            exportButton.addEventListener('click', function() {
                const paletteData = [];
                
                inputs.forEach(input => {
                    const name = input.title.split(' - ')[0];
                    const cssVar = input.dataset.cssVar;
                    const slug = cssVar.replace('--wp--preset--color--', '');
                    
                    paletteData.push({
                        slug: slug,
                        name: name,
                        color: input.value
                    });
                });
                
                const jsonString = JSON.stringify(paletteData, null, 2);
                jsonOutput.textContent = jsonString;
                jsonOutput.style.display = 'block';
                
                // Copier dans le presse-papier
                navigator.clipboard.writeText(jsonString).then(() => {
                    alert('Palette JSON copiée dans le presse-papier !');
                }).catch(err => {
                    console.error('Erreur lors de la copie : ', err);
                });
            });
        });
    </script>
    <?php
    return ob_get_clean();
}
