<?php

add_shortcode('modifier_palette_dynamique', 'formulaire_palette_dynamique');
function formulaire_palette_dynamique() {
    // Ensure the WP_Theme_JSON_Resolver class is available
    if (!class_exists('WP_Theme_JSON_Resolver')) {
        return '<p>Cette fonctionnalité nécessite WordPress 5.9 ou supérieur.</p>';
    }
    
    // Charger les Dashicons pour les icônes
    wp_enqueue_style('dashicons');

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

        // Enregistrer dans le post wp_global_styles
        if (class_exists('WP_Theme_JSON_Resolver')) {
            // Récupérer le post global-styles ou le créer s'il n'existe pas
            $global_styles_post = null;
            
            // Rechercher le post global-styles
            $query = new WP_Query([
                'post_type'      => 'wp_global_styles',
                'posts_per_page' => 1,
                'no_found_rows'  => true,
            ]);
            
            if ($query->have_posts()) {
                $query->the_post();
                $global_styles_post_id = get_the_ID();
                $global_styles_post = get_post($global_styles_post_id);
                wp_reset_postdata();
            }
            
            if ($global_styles_post) {
                // Le post existe, mettre à jour son contenu
                $content = json_decode($global_styles_post->post_content, true);
                if (is_array($content)) {
                    if (!isset($content['settings'])) {
                        $content['settings'] = [];
                    }
                    if (!isset($content['settings']['color'])) {
                        $content['settings']['color'] = [];
                    }
                    $content['settings']['color']['palette'] = ['theme' => $nouvelles_couleurs];
                    
                    wp_update_post([
                        'ID'           => $global_styles_post->ID,
                        'post_content' => wp_json_encode($content),
                    ]);
                }
            } else {
                // Créer un nouveau post global-styles
                $content = [
                    'version' => 2,
                    'settings' => [
                        'color' => [
                            'palette' => [
                                'theme' => $nouvelles_couleurs
                            ]
                        ]
                    ]
                ];
                
                wp_insert_post([
                    'post_type'    => 'wp_global_styles',
                    'post_status'  => 'publish',
                    'post_content' => wp_json_encode($content),
                ]);
            }
            
            // Vider le cache
            delete_transient('global_styles');
            delete_transient('global_styles_' . get_stylesheet());
        } else {
            // Fallback : enregistrer dans les options
            update_option('theme_palette_custom', $nouvelles_couleurs);
        }

        // Rediriger pour éviter la soumission multiple du formulaire
        wp_redirect(add_query_arg('palette_updated', '1', $_SERVER['REQUEST_URI']));
        exit;
    }
    
    // Traitement du formulaire : création d'une nouvelle palette
    if (isset($_POST['creer_nouvelle_palette'])) {
        // Récupérer les couleurs du formulaire
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
        
        // Créer un nouveau fichier de palette dans le répertoire styles/colors
        $styles_dir = get_template_directory() . '/styles/colors/';
        
        // Vérifier si le répertoire existe
        if (!file_exists($styles_dir)) {
            wp_mkdir_p($styles_dir);
        }
        
        // Générer un nom pour la nouvelle palette
        $date = new DateTime();
        $palette_name = 'custom-palette-' . $date->format('YmdHis');
        $palette_title = 'Custom Palette ' . $date->format('Y-m-d H:i:s');
        
        // Déterminer le numéro du fichier
        $existing_files = glob($styles_dir . '*.json');
        $next_number = count($existing_files) + 1;
        $file_name = sprintf('%02d', $next_number) . '-' . $palette_name . '.json';
        
        // Créer le contenu du fichier JSON
        $palette_data = [
            '$schema' => 'https://schemas.wp.org/trunk/theme.json',
            'version' => 3,
            'title' => $palette_title,
            'settings' => [
                'color' => [
                    'palette' => $nouvelles_couleurs
                ]
            ]
        ];
        
        // Ajouter la section duotone si les deux premières couleurs existent
        if (count($nouvelles_couleurs) >= 2) {
            $palette_data['settings']['color']['duotone'] = [
                [
                    'colors' => [
                        $nouvelles_couleurs[0]['color'],
                        $nouvelles_couleurs[1]['color']
                    ],
                    'name' => $palette_title . ' filter',
                    'slug' => sanitize_title($palette_name) . '-filter'
                ]
            ];
        }
        
        // Écrire le fichier
        $file_path = $styles_dir . $file_name;
        $json_content = wp_json_encode($palette_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        
        if (file_put_contents($file_path, $json_content)) {
            // Enregistrer également dans le post wp_global_styles pour appliquer immédiatement
            if (class_exists('WP_Theme_JSON_Resolver')) {
                // Récupérer le post global-styles ou le créer s'il n'existe pas
                $global_styles_post = null;
                
                // Rechercher le post global-styles
                $query = new WP_Query([
                    'post_type'      => 'wp_global_styles',
                    'posts_per_page' => 1,
                    'no_found_rows'  => true,
                ]);
                
                if ($query->have_posts()) {
                    $query->the_post();
                    $global_styles_post_id = get_the_ID();
                    $global_styles_post = get_post($global_styles_post_id);
                    wp_reset_postdata();
                }
                
                if ($global_styles_post) {
                    // Le post existe, mettre à jour son contenu
                    $content = json_decode($global_styles_post->post_content, true);
                    if (is_array($content)) {
                        if (!isset($content['settings'])) {
                            $content['settings'] = [];
                        }
                        if (!isset($content['settings']['color'])) {
                            $content['settings']['color'] = [];
                        }
                        $content['settings']['color']['palette'] = ['theme' => $nouvelles_couleurs];
                        
                        wp_update_post([
                            'ID'           => $global_styles_post->ID,
                            'post_content' => wp_json_encode($content),
                        ]);
                    }
                } else {
                    // Créer un nouveau post global-styles
                    $content = [
                        'version' => 2,
                        'settings' => [
                            'color' => [
                                'palette' => [
                                    'theme' => $nouvelles_couleurs
                                ]
                            ]
                        ]
                    ];
                    
                    wp_insert_post([
                        'post_type'    => 'wp_global_styles',
                        'post_status'  => 'publish',
                        'post_content' => wp_json_encode($content),
                    ]);
                }
                
                // Vider le cache
                delete_transient('global_styles');
                delete_transient('global_styles_' . get_stylesheet());
            } else {
                // Fallback : enregistrer dans les options
                update_option('theme_palette_custom', $nouvelles_couleurs);
            }
            
            // Rediriger pour éviter la soumission multiple du formulaire
            wp_redirect(add_query_arg('palette_created', '1', $_SERVER['REQUEST_URI']));
        } else {
            // En cas d'erreur d'écriture du fichier
            wp_redirect(add_query_arg('palette_error', '1', $_SERVER['REQUEST_URI']));
        }
        exit;
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
        
        // Rediriger pour éviter la soumission multiple du formulaire
        wp_redirect(add_query_arg('palette_reset', '1', $_SERVER['REQUEST_URI']));
        exit;
    }

    // Affichage du formulaire
    ob_start();
    
    // Afficher les messages de confirmation
    if (isset($_GET['palette_updated'])) {
        echo '<div class="notice notice-success"><p>Palette de couleurs mise à jour avec succès.</p></div>';
    } elseif (isset($_GET['palette_reset'])) {
        echo '<div class="notice notice-success"><p>Palette de couleurs réinitialisée avec succès.</p></div>';
    } elseif (isset($_GET['palette_created'])) {
        echo '<div class="notice notice-success"><p>Nouvelle palette de couleurs créée avec succès et enregistrée dans le dossier des styles.</p></div>';
    } elseif (isset($_GET['palette_error'])) {
        echo '<div class="notice notice-error"><p>Erreur lors de la création du fichier de palette. Vérifiez les permissions d\'écriture.</p></div>';
    }
    ?>
    <form method="post" id="form-palette">
        <h3>Modifier dynamiquement la palette du thème</h3>
        
        <div id="palette-control-panel" class="palette-control-panel expanded">
            <div class="palette-toggle">
                <button type="button" id="toggle-palette" class="toggle-button">
                    <span class="dashicons dashicons-admin-appearance"></span>
                </button>
            </div>
            
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
                <button type="submit" name="reinitialiser_palette" class="icon-button" title="Réinitialiser la palette">
                    <span class="dashicons dashicons-image-rotate"></span>
                </button>
                <button type="submit" name="creer_nouvelle_palette" class="icon-button" title="Créer nouvelle palette">
                    <span class="dashicons dashicons-welcome-add-page"></span>
                </button>
                <button type="button" id="export-json" class="icon-button" title="Exporter en JSON">
                    <span class="dashicons dashicons-download"></span>
                </button>
                <button type="button" id="import-json-btn" class="icon-button" title="Importer JSON">
                    <span class="dashicons dashicons-upload"></span>
                </button>
            </div>
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
        /* Panel principal */
        .palette-control-panel {
            position: fixed;
            bottom: 20px;
            left: 20px;
            z-index: 9999;
            background-color: #fff;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            padding: 10px;
            transition: all 0.3s ease;
            max-width: 90vw;
            opacity: 0.95;
        }
        
        .palette-control-panel:hover {
            opacity: 1;
        }
        
        /* Adaptation mobile */
        @media (max-width: 768px) {
            .palette-control-panel {
                bottom: 10px;
                left: 10px;
                padding: 8px;
            }
            
            .color-square-container input[type="color"] {
                width: 35px;
                height: 35px;
            }
            
            .icon-button {
                width: 32px;
                height: 32px;
            }
        }
        
        /* État réduit */
        .palette-control-panel.collapsed {
            width: 50px;
            height: 50px;
            overflow: hidden;
            opacity: 0.7;
            transform: scale(0.9);
        }
        
        .palette-control-panel.collapsed:hover {
            opacity: 0.9;
        }
        
        .palette-control-panel.collapsed .palette-container,
        .palette-control-panel.collapsed .action-buttons {
            opacity: 0;
            visibility: hidden;
        }
        
        /* Bouton toggle */
        .palette-toggle {
            position: absolute;
            top: -15px;
            right: -15px;
            z-index: 10000;
        }
        
        .toggle-button {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background-color: #2271b1;
            color: white;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.2);
        }
        
        .toggle-button:hover {
            background-color: #135e96;
        }
        
        /* Conteneur de palette */
        .palette-container { 
            display: flex; 
            flex-wrap: wrap; 
            gap: 10px; 
            margin: 10px 0;
            transition: opacity 0.3s ease, visibility 0.3s ease;
        }
        
        /* Conteneurs de couleurs */
        .color-square-container { 
            position: relative; 
        }
        
        .color-square-container input[type="color"] { 
            width: 40px; 
            height: 40px; 
            padding: 0; 
            border: none; 
            border-radius: 4px; 
            cursor: pointer; 
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        
        .color-square-container input[type="color"]:hover { 
            transform: scale(1.1);
            box-shadow: 0 3px 6px rgba(0,0,0,0.15);
        }
        
        .color-square-container input[type="color"]::-webkit-color-swatch-wrapper { 
            padding: 0; 
        }
        
        .color-square-container input[type="color"]::-webkit-color-swatch { 
            border: none; 
            border-radius: 4px; 
        }
        
        /* Boutons d'action */
        .action-buttons { 
            margin-top: 10px; 
            display: flex; 
            gap: 8px;
            justify-content: center;
            transition: opacity 0.3s ease, visibility 0.3s ease;
        }
        
        .icon-button {
            width: 36px;
            height: 36px;
            border-radius: 4px;
            background-color: #f0f0f1;
            border: 1px solid #c3c4c7;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0;
            color: #3c434a;
            transition: all 0.2s ease;
        }
        
        .icon-button:hover {
            background-color: #e0e0e1;
            color: #135e96;
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
            const toggleButton = document.getElementById('toggle-palette');
            const palettePanel = document.getElementById('palette-control-panel');
            
            // Gestion du toggle pour afficher/cacher la palette
            toggleButton.addEventListener('click', function() {
                palettePanel.classList.toggle('collapsed');
                // Sauvegarder l'état dans localStorage
                localStorage.setItem('palette_panel_collapsed', palettePanel.classList.contains('collapsed'));
                
                // Mettre à jour l'icône du bouton
                const iconElement = this.querySelector('.dashicons');
                if (palettePanel.classList.contains('collapsed')) {
                    iconElement.classList.remove('dashicons-admin-appearance');
                    iconElement.classList.add('dashicons-visibility');
                } else {
                    iconElement.classList.remove('dashicons-visibility');
                    iconElement.classList.add('dashicons-admin-appearance');
                }
            });
            
            // Restaurer l'état précédent au chargement
            if (localStorage.getItem('palette_panel_collapsed') === 'true') {
                palettePanel.classList.add('collapsed');
                // Mettre à jour l'icône du bouton
                const iconElement = toggleButton.querySelector('.dashicons');
                iconElement.classList.remove('dashicons-admin-appearance');
                iconElement.classList.add('dashicons-visibility');
            }
            
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
