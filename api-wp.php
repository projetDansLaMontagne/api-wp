<?php

/**
 * Plugin Name: VAPI
 * Plugin URI: https://www.valpineta.eu/
 * Description: Plugin d'API pour notre application mobile
 * Author: ALONZO Robin, PLANCHE Tom
 * Version: 1.2.1
 */

if (!defined('ABSPATH')) {
    exit;
}

/** =============================================== INCLUDES ======================================================= */
include_once 'includes/gpxToJson.php';
include_once 'includes/functionUtils.php';

/** =============================================== GLOBAL VARIABLES ================================================ */

$debug = true;
const DISTANCE_MAX = 30;

/** =============================================== ADD BUTTON DEBUG MODE ================================================= */
function linkDebugMode($links): array
{
    global $debug;
    if ($debug) {
        $linkState = '<a href="' . admin_url('admin-post.php?action=desactivate_debug_mode') . '">DésactiverDebug</a>';
    } else {
        $linkState = '<a href="' . admin_url('admin-post.php?action=activate_debug_mode') . '">ActiverDebug</a>';
    }

    array_unshift($links, $linkState);
    return $links;
}

/** =============================================== HANDLE DEBUG MODE ================================================= */
function handleDebugMode()
{
    // Update le debug
    global $debug;

    //Update le fichier
    $file = file_get_contents(__FILE__);
    $file = str_replace('$debug = ' . ($debug ? 'true' : 'false'), '$debug = ' . ($debug ? 'false' : 'true'), $file);
    file_put_contents(__FILE__, $file);

    // Rediriger vers la page des extensions installées
    wp_redirect(admin_url('plugins.php'));
    exit;
}

/** ============================================== FUNCTIONS API ======================================================================*/

/**
 * @function getFile
 * @description Get the file
 * @return array|WP_Error
 * @autor Robin Alonzo
 */
function getFile(WP_REST_Request $request)
{

    // Get the data from the request
    $request = $request->get_params();

    // Get the file
    $file = $request['file'];

    // Get the upload directory
    $uploadDir = wp_upload_dir();

    // Get the path of the upload directory
    $uploadPath = $uploadDir['basedir'];

    // Get the file
    $fichier = $uploadPath . "/" . $file;

    // Verify if the file exists and if it is a file
    if (file_exists($fichier) && is_file($fichier)) {
        //Clean the output buffer
        ob_clean();

        // Define the header
        header('Content-Description: File Transfer');
        header('Content-Type: application/' . pathinfo($fichier, PATHINFO_EXTENSION));
        header('Content-Disposition: attachment; filename="' . basename($fichier) . '"');
        header('Content-Length: ' . filesize($fichier));

        // Download
        readfile($fichier);
        exit();
    } else {
        return new WP_Error('fichier_inexistant', 'Le fichier n\'existe pas', array('status' => 404));
    }
}

/** @function getMd5File
 * @description Get the md5 of the file
 * @param WP_REST_Request $request
 * @return string
 */
function getMd5File(WP_REST_Request $request)
{
    // Get the data from the request
    $request = $request->get_params();

    // Get the file
    $file = $request['file'];

    // Get the upload directory
    $uploadDir = wp_upload_dir();

    // Get the path of the upload directory
    $uploadPath = $uploadDir['basedir'];

    // Get the file
    $fichier = $uploadPath . "/" . $file;

    // Verify if the file exists
    if (!file_exists($fichier)) {
        return new WP_Error('fichier_inexistant', 'Le fichier n\'existe pas', array('status' => 404));
    }

    // Get the md5 of the file
    $md5DuFichier = hash_file('md5', $fichier);

    return new WP_REST_RESPONSE($md5DuFichier, 200);
}

/**
 * @function getExcursions
 * @description Get all the excursions in the database
 * @return array|WP_REST_RESPONSE
 * @autor Robin Alonzo & Tom Planche
 */
function getExcursions(WP_REST_Request $request)
{
    $params = array(
        'limit' => -1,
        'isMd5' => 'false'
    );

    // Fusionner les paramètres par défaut avec ceux de la demande
    $params = array_merge($params, $request->get_params());

    // Get the data from the request
    $md5Bool = $params['isMd5'];

    // Convert the md5_bool to boolean
    $md5Bool = filter_var($md5Bool, FILTER_VALIDATE_BOOLEAN);

    // Fetch all the excursions in the pods
    $podsSelect = pods('excursion', $params);

    // Fill the data array
    $finalData = array();

    if (0 < $podsSelect->total()) {
        // Loop through the excursions
        while ($podsSelect->fetch()) {
            // Récupérer l'ID du post
            $postId = $podsSelect->id();

            // Récupérer la taxonomie
            $typeParcours = $podsSelect->field('type_parcours');

            #Espagnol
            // Réupérer le titre en es
            $postTitleEs = $podsSelect->field('post_title');

            // Récupérer la description en es
            $postContentEs = cleanDescription($podsSelect->field('post_content'));

            // Recuperer le nom du type de parcours en es
            $typeParcoursEs = $typeParcours['name'];

            #Français
            // Récupérer le post_id de la version fr
            $translatedPostId = apply_filters('wpml_object_id', $postId, 'post', TRUE, 'fr');

            //Récupérer le titre traduit dans la langue sélectionnée
            $postTitleFr = get_post_field('post_title', $translatedPostId);

            // Récupérer la description traduit en fr
            $postContentFrNonFormate = get_post_field('post_content', $translatedPostId);
            // Formatage du contenu de l'excursion
            $postContentFr = cleanDescription($postContentFrNonFormate);

            // Traduire type_parcours_es en fr
            $typeParcoursIdEs = $typeParcours['term_id'];
            $typeParcoursIdFr = apply_filters('wpml_object_id', $typeParcoursIdEs, 'type_de_parcours', TRUE, 'fr');
            $typeParcoursFr = get_term($typeParcoursIdFr, 'type_de_parcours')->name;

            // Get the signalement in the database
            global $wpdb;
            $signalements = $wpdb->prepare("SELECT * FROM signalement INNER JOIN post_signalement_relation ON signalement.id = post_signalement_relation.idSignalement WHERE post_signalement_relation.idExcursion = %d", $postId);
            $wpdb->query($signalements);

            // Verify if the query is correct
            if ($wpdb->last_error !== '') {
                return new WP_Error('erreur_bdd', 'Erreur lors de la récupération des signalements', array('status' => 500));
            }

            // Handle the duration
            $duree = $podsSelect->field('duree');
            $formatDuree = cleanDuree($duree);

            if ($formatDuree['h'] == 00 && $formatDuree['m'] == 00) {
                continue;
            }

            // Get the signalements
            $signalements = $wpdb->get_results($signalements);

            // Loop through the signalements
            foreach ($signalements as $signalement) {
                $signalement->lat = floatval($signalement->lat);
                $signalement->lon = floatval($signalement->lon);
                $signalement->idExcursion = intval($signalement->idExcursion);
            }

            // Add the excursion to the data array
            $finalData[] = array(
                'denivele' => intval($podsSelect->field('denivele_de_montee')),
                'difficulteOrientation' => intval($podsSelect->field('difficulte_orientation')),
                'difficulteTechnique' => intval($podsSelect->field('difficulte_technique')),
                'distance' => floatval($podsSelect->field('distance_excursion')),
                'duree' => $formatDuree,
                'vallee' => $podsSelect->field('vallee'),
                'postId' => $podsSelect->field('ID'),
                'signalements' => $signalements,
                'nomTrackGpx' => $podsSelect->field('track')[post_title] . '.gpx',

                'track' => getJsonTrack($podsSelect->field('track')[post_title] . '.json'),

                'es' => array(
                    "nom" => $postTitleEs,
                    "description" => $postContentEs,
                    "typeParcours" => $typeParcoursEs,
                ),
                'fr' => array(
                    "nom" => $postTitleFr,
                    "description" => $postContentFr,
                    "typeParcours" => $typeParcoursFr,
                ),
            );
        }

    }

    // Get md5 of the final_data
    $jsonData = json_encode($finalData);
    $md5 = hash('md5', $jsonData);

    // If md5 true return only md5
    if ($md5Bool) {
        return new WP_REST_RESPONSE($md5, 200);
    }
    else // return only final_data
    {
        return new WP_REST_RESPONSE($finalData, 200);
    }
}

/**
 * @function setSignalement
 * @description Set the excursion
 * @param WP_REST_Request $request
 * @return array|WP_REST_RESPONSE
 */
function setSignalement(WP_REST_Request $request)
{
    // Get the data from the request
    $data = $request->get_params();

    $signalements = json_decode($data['signalements']);

    // Verify $signalements
    if (!is_array($signalements)) {
        // Return content of the request in case of error
        return new WP_ERROR('signalements_incorrect', 'Le signalement doit être un tableau', array('status' => 400));
    }

    // Get file listCoordinates.json in uploads directory
    $uploadDir = wp_upload_dir();

    // Get the path of the upload directory
    $uploadPath = $uploadDir['basedir'];

    // Get the file in uploads/imagesSignalements
    $fileEncode = $uploadPath . "/utileAPI/listMatchCoordinates.json";

    if (!file_exists($fileEncode)) {
        return new WP_ERROR('fichier_inexistant', 'Le fichier n\'existe pas', array('status' => 404));
    }

    $fileDecode = json_decode(file_get_contents($fileEncode), true);

    $listExcursions = array();

    // Compare the signalements with the excursions
    foreach ($signalements as $signalement) {
        // Insert the signalement in the database
        $idSignalement = insertSignalementInBD($signalement->nom, $signalement->type, $signalement->description, $signalement->image, $signalement->lat, $signalement->lon, $signalement->postId);

        // Verify if the query is correct
        if ($idSignalement == false) {
            return new WP_ERROR('erreur_bdd', 'Erreur lors de l\'insertion du signalement', array('status' => 500));
        }

        // Get the name of the excursion
        $nomExcursionInitial = get_post_field('post_title', $signalement->postId);

        // Verify if nomExcursionInitial is not in the list if no insert
        if (!in_array($nomExcursionInitial, $listExcursions)) {
            array_push($listExcursions, $nomExcursionInitial);
        }

        // Get the coordinates of the signalement
        $coordSignalement = array(
            'lat' => floatval($signalement->lat),
            'lon' => floatval($signalement->lon)
        );

        // Loop through the fileDecode with value and key
        foreach ($fileDecode as $key => $value)
        {
            // Separate the key
            $keyExplode = explode('_', $key);

            // Get the coordinates of the key
            $coordMatch = array(
                'lat' => floatval($keyExplode[0]),
                'lon' => floatval($keyExplode[1])
            );

            // Get the distance between the two coordinates
            $distance = getDistanceBetweenTwoCoordinates($coordSignalement, $coordMatch, true);

            // Verify if the distance is less than 30 meters
            if ($distance <= DISTANCE_MAX) {
                // Get the id of the excursion
                $idExcursion = intval($value[0]['postId']);
                $nomExcursion = $value[0]['nomExcursion'];

                // Insert the relation signalement in the database
                insertRelationInBD($idSignalement, $idExcursion);

                // Verify if nomExcursionInitial is not in the list if no insert
                if (!in_array($nomExcursion, $listExcursions)) {
                    array_push($listExcursions, $nomExcursion);
                }
            }
        }
        // Write the code HTML
        $corps = writeTheCodeHTML($signalement, $listExcursions);
        sendEmail("robin.alonzo03@gmail.com", "[VALPINETA-EU] NOUVEAU SIGNALEMENT", $corps);
    }
    return new WP_REST_RESPONSE("L'insertion des signalements c'est bien passé", 200);
}

/* =============================================== BASE DE DONNEES ================================================= */
/**
 * @function getSignalements
 * @description Get all the signalements in the database
 * @return array|WP_REST_RESPONSE
 */
function getSignalements()
{
    // Get the database
    global $wpdb;

    // Get all the signalements
    $signalements = $wpdb->prepare('SELECT * FROM signalement');
    $wpdb->query($signalements);

    // Get all relations
    $relations = $wpdb->prepare('SELECT * FROM post_signalement_relation');
    $wpdb->query($relations);

    // Verify if the query is correct
    if ($wpdb->last_error !== '') {
        return new WP_ERROR('erreur_bdd', 'Erreur lors de la récupération des signalements', array('status' => 500));
    }

    // Get the signalements
    $signalements = $wpdb->get_results($signalements);

    // Get the relations
    $relations = $wpdb->get_results($relations);

    // return the signalements and the relations
    return new WP_REST_RESPONSE(array(
        'signalements' => $signalements,
        'relations' => $relations
    ), 200);
}

/**
 * @function createTable
 * @description Create table signalement and post_signalement_relation in the database
 */
function createTable()
{
    // Get the database
    global $wpdb;

    // Create the table signalement
    $tableSignalement = $wpdb->prepare('CREATE TABLE IF NOT EXISTS signalement (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        nom VARCHAR(255) NOT NULL,
        type VARCHAR(255) NOT NULL CHECK (type IN ("Avertissement", "PointInteret")),
        description TEXT NOT NULL,
        image LONGTEXT NOT NULL,
        lat FLOAT NOT NULL,
        lon FLOAT NOT NULL
    )');
    $wpdb->query($tableSignalement);

    printf($wpdb->last_error);

    // Verify if the query is correct
    if ($wpdb->last_error !== '') {
        return new WP_ERROR('erreur_bdd', 'Erreur lors de la création de la table signalements', array('status' => 500));
    }

    // Get the prefix
    global $table_prefix;
    $post_table = $table_prefix . 'posts';

    // Create the table post_signalement_relation
    $tableRelation = $wpdb->prepare("CREATE TABLE IF NOT EXISTS post_signalement_relation (
        idSignalement BIGINT UNSIGNED NOT NULL,
        idExcursion BIGINT UNSIGNED NOT NULL,
        PRIMARY KEY (idSignalement, idExcursion),
        FOREIGN KEY (idSignalement) REFERENCES signalement(id),
        FOREIGN KEY (idExcursion) REFERENCES $post_table(ID))
        ");
    $wpdb->query($tableRelation);

    // Verify if the query is correct
    if ($wpdb->last_error !== '') {
        return new WP_ERROR('erreur_bdd', 'Erreur lors de la création de la table post_signalement_relation', array('status' => 500));
    }

    return new WP_REST_RESPONSE("La création des tables c'est bien passé", 200);
}

/**
 * @function dropTable
 * @description Drop table signalement in the database
 * @return array|WP_REST_RESPONSE
 */
function dropTable()
{
    // Get the database
    global $wpdb;

    // Drop the table post_signalement_relation
    $tableRelation = $wpdb->prepare('DROP TABLE IF EXISTS post_signalement_relation');
    $wpdb->query($tableRelation);

    // Verify if the query is correct
    if ($wpdb->last_error !== '') {
        return new WP_ERROR('erreur_bdd', 'Erreur lors de la supression de la table post_signalement_relation', array('status' => 500));
    }

    // Drop the table signalement
    $tableSignalement = $wpdb->prepare('DROP TABLE IF EXISTS signalement');
    $wpdb->query($tableSignalement);

    // Verify if the query is correct
    if ($wpdb->last_error !== '') {
        return new WP_ERROR('erreur_bdd', 'Erreur lors de la supression de la table signalements', array('status' => 500));
    }

    return new WP_REST_RESPONSE("La supression des tables s'est bien déroulé", 200);
}

/**
 * @function showTables
 * @description Show tables in the database
 * @return WP_ERROR|WP_REST_RESPONSE
 */
function showTables()
{
    // Get the database
    global $wpdb;

    // Show tables
    $tables = $wpdb->prepare('SHOW TABLES');
    $wpdb->query($tables);

    // Verify if the query is correct
    if ($wpdb->last_error !== '') {
        return new WP_ERROR('erreur_bdd', "Erreur lors de l'affichage des tables", array('status' => 500));
    }

    // Get the tables
    $tables = $wpdb->get_results($tables);

    return new WP_REST_RESPONSE($tables, 200);
}

/* =============================================== CRON ================================================= */
/**
 * @function activateTaskCronDaily
 * @description This function is used to run the cron job
 * @return void
 */
function activateTaskCronDaily() {
    if (!wp_next_scheduled('CRON_EVENT')) {

        // Schedule the event to run daily
        wp_schedule_event(time(), 'hourly', 'CRON_EVENT');
    }
}

/* ================================================ WORDPRESS HOOKS ================================================ */

// Register the function to rest_api_init
// This is the function that registers the routes:
// - /wp-json/api-wp/excursions
// - /wp-json/api-wp/dl-file
// if debug mode is activated:
// - /wp-json/api-wp/drop-tables
// - /wp-json/api-wp/create-tables
// - /wp-json/api-wp/show-tables
// - /wp-json/api-wp/signalements
// - /wp-json/api-wp/convert-gpx
// - /wp-json/api-wp/set-signalement
function register_api()
{
    register_rest_route('api-wp', 'excursions', array(
        'methods' => 'GET',
        'callback' => 'getExcursions',
        'permission_callback' => '__return_true',
    ));

    register_rest_route('api-wp', 'dl-file', array(
        'methods' => 'GET',
        'callback' => 'getFile',
        'permission_callback' => '__return_true',
    ));

    register_rest_route('api-wp', 'md5-file', array(
        'methods' => 'GET',
        'callback' => 'getMd5File',
        'permission_callback' => '__return_true',
    ));

    register_rest_route('api-wp', 'set-signalement', array(
        'methods' => 'POST',
        'callback' => 'setSignalement',
        'permission_callback' => '__return_true',
    ));

    /* =============================================== DEBUG ================================================= */

    global $debug;
    if ($debug) {

        register_rest_route('api-wp', 'drop-tables', array(
            'methods' => 'POST',
            'callback' => 'dropTable',
            'permission_callback' => '__return_true',
        ));

        register_rest_route('api-wp', 'create-tables', array(
            'methods' => 'POST',
            'callback' => 'createTable',
            'permission_callback' => '__return_true',
        ));

        register_rest_route('api-wp', 'show-tables', array(
            'methods' => 'GET',
            'callback' => 'showTables',
            'permission_callback' => '__return_true',
        ));

        register_rest_route('api-wp', 'get-signalements', array(
            'methods' => 'GET',
            'callback' => 'getSignalements',
            'permission_callback' => '__return_true',
        ));

        register_rest_route('api-wp', 'convert-gpx', array(
            'methods' => 'GET',
            'callback' => 'convertGpxToJson',
            'permission_callback' => '__return_true',
        ));

        register_rest_route('api-wp', 'test', array(
            'methods' => 'GET',
            'callback' => 'testFunction',
            'permission_callback' => '__return_true',
        ));
    }
}

// Add the create_table function to the activation hook of the plugin
register_activation_hook(__FILE__, 'create_table');

// Add the task to the cron
register_activation_hook(__FILE__, 'activateTaskCronDaily');

// Add the action to the cron event
add_action('CRON_EVENT', 'convertGpxToJsonAndUploadListJson');
add_action( 'pods_api_post_save_pod_item_excursion', 'convertGpxToJsonAndUploadListJson', 10, 3);

// Add the rest_api_init action
add_action('rest_api_init', __NAMESPACE__ . '\\register_api');

// Add the link_debug_mode function to the plugin_action_links hook
add_action('plugin_action_links_' . plugin_basename(__FILE__), 'linkDebugMode', 10);

// Add the handle_debug_mode function to the admin_post_desactivate_debug_mode and admin_post_activate_debug_mode hooks
add_action('admin_post_desactivate_debug_mode', 'handleDebugMode');
add_action('admin_post_activate_debug_mode', 'handleDebugMode');
