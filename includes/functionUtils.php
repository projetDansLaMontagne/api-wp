<?php

define('DISTANCE_MAX_COORD', 10);

/**
 * @function cleanDescription
 * @description Clean the text
 * @param $postContent
 * @return string
 * @autor Robin Alonzo
 */
function cleanDescription($postContent)
{
    // Supprimer tout après chaque balise <a>
    $postContent = preg_replace('/<a\b[^>]*>.*?<\/a>/', '<a></a>', $postContent);

    // Supprimer les espaces en trop
    $postContent = preg_replace('/\s+/', ' ', $postContent);

    // Supprimer les mots commençant par un &#
    $postContent = preg_replace('/&#\w+/', '', $postContent);

    // Supprimer toutes les balises <a> et <img> et leur contenu
    $postContent = preg_replace('/<a\b[^>]*>.*?<\/a>|<img\b[^>]*>/', '', $postContent);

    // Supprimer les chaînes <!-- wp:fl-builder/layout --> et <!-- /wp:fl-builder/layout -->
    $postContent = preg_replace('/<!--\s*\/?wp:fl-builder\/layout[^>]* -->/', '', $postContent);

    return $postContent;
}

/**
 * @function cleanDuree
 * @description Clean the duration
 * @param $duree
 */
function cleanDuree($duree)
{
    // Format de la durée : 00h00
    $duree = preg_replace('/^(\d{1,2})h(\d{0,2})$/', '$1h$2', $duree);


    preg_match('/^(\d{1,2})h(\d{0,2})$/', $duree, $matches);

    return array(
        'h' => intval($matches[1]),
        'm' => intval($matches[2])
    );
}

/**
 * @function getJsonTrack
 * @description Get the json track
 * @param $file
 * @return array
 */
function getJsonTrack($file)
{
    // Get the upload directory
    $uploadDir = wp_upload_dir();

    // Get the path of the upload directory
    $uploadPath = $uploadDir['basedir'];

    // Get the path of the track directory
    $trackPath = $uploadPath . '/tracks';

    // Get the path of the track file
    $trackFile = $trackPath . '/' . $file;

    // Get the content of the track file
    $trackContent = file_get_contents($trackFile);

    // Get the json of the track file
    $trackJson = json_decode($trackContent, true);

    // Return the json of the track file
    return $trackJson;
}

/**
 * @function getListJsonTracks
 * @description Get the list of json tracks
 * @return array
 */
function getListJsonFiles()
{
    // Define the parameters
    $params = array(
        'limit' => -1,
    );

    // Init list of json tracks
    $trackFileNames = array();

    // Get the excursions
    $podsSelect = pods('excursion', $params);

    // Get the list of json tracks
    if (0 < $podsSelect->total()) {
        // Loop through the excursions
        while ($podsSelect->fetch()) {
            $nomTrack = $podsSelect->field('track')[post_title] . '.json';
            $nomExcursion = $podsSelect->field('post_title');
            $postId = $podsSelect->id();

            // Add the json track in the list
            $trackFileNames[$postId] = array(
                'nomTrack' => $nomTrack,
                'nomExcursion' => $nomExcursion
            );
        }
    }

    // Return the list of json tracks
    return $trackFileNames;
}

/**
 * @function getDistanceBetweenTwoCoordinates
 * @description Get the distance between two coordinates in km
 * @param $coordSignalement
 * @param $coordTrack
 * @param $inMeter (true if you want the distance in meter)
 * @return float|int
 */
function getDistanceBetweenTwoCoordinates($coordSignalement, $coordTrack, $inMeter = false) {
    // Get the latitude and longitude of the signalement
    $lat1 = deg2rad($coordSignalement['lat']);
    $lon1 = deg2rad($coordSignalement['lon']);

    // Get the latitude and longitude of the track
    $lat2 = deg2rad($coordTrack['lat']);
    $lon2 = deg2rad($coordTrack['lon']);

    // Get the radius of the earth
    $R = 6371.0;

    // Calculate the differences in radians
    $dLat = $lat2 - $lat1;
    $dLon = $lon2 - $lon1;

    // Calculate the haversine formula
    $a = sin($dLat / 2.0) * sin($dLat / 2.0) + sin($dLon / 2.0) * sin($dLon / 2.0) * cos($lat1) * cos($lat2);
    $c = 2.0 * atan2(sqrt($a), sqrt(1.0 - $a));

    // Calculate the distance between the two coordinates
    $distance = $R * $c;

    // If the distance is in meter
    if ($inMeter) {
        // Convert the distance in meter
        $distance *= 1000;
    }

    // Return the distance between the two coordinates
    return $distance;
}

/** @function envoieEmail
 * @description Envoie un email
 * @param $email string L'adresse email du destinataire
 * @param $subject string Le sujet du mail
 * @param $message string Le message du mail
 * @return bool
 */
function envoieEmail($to, $subject, $message, $headers, $attachments = null)
{
    //Add name to the header
    $headers .= 'From: ' . get_bloginfo('name') . ' <' . get_bloginfo('admin_email') . '>' . "\r\n";

    // Send the email
    return wp_mail($to, $subject, $message, $headers, $attachments);
}

/**
 * @function matchCoordinatesBetweenAllTracks
 * @description Match the coordinates between all the tracks
 * @param $tracks
 * @return void
 */
function matchCoordinatesBetweenAllTracks()
{
    // Final array of coordinates finalCoordinates
    $finalCoordinates = array();

    // Get the list of json tracks
    $trackFiles = getListJsonFiles();

    // Init coordLastTrack
    $coordLastTrack = null;

    // Get keys of the track files
    $trackFilesKeys = array_keys($trackFiles);

    // For each track file
    for ($i = 0; $i < count($trackFiles); $i ++)
    {
        // Get the json of the track file
        $trackJson = getJsonTrack($trackFiles[$trackFilesKeys[$i]]['nomTrack']);
        // Get the next json of the track file
        $nextTrackJson = getJsonTrack($trackFiles[$trackFilesKeys[$i + 1]]['nomTrack']);

        foreach ($trackJson as $coord)
        {
            // Get the coordinates of the track
            $coordTrack = array(
                'lat' => $coord['lat'],
                'lon' => $coord['lon']
            );

            foreach ($nextTrackJson as $nextCoord)
            {
                // Get the coordinates of the nex track
                $coordNextTrack = array(
                    'lat' => $nextCoord['lat'],
                    'lon' => $nextCoord['lon']
                );

                // Get the distance between the two coordinates
                $distance = getDistanceBetweenTwoCoordinates($coordTrack, $coordNextTrack, true);

                // If the distance is less than 10 meters
                if ($distance < DISTANCE_MAX_COORD)
                {
                    // Create a key with the coordinates
                    $coordTrackKey = $coordTrack['lat'] . '_' . $coordTrack['lon'];

                    if ($coordNextTrack === $coordLastTrack) {
                        continue;
                    }
                    // If the key doesn't exist
                    if (!isset($finalCoordinates[$coordTrackKey]))
                    {
                        // Create the key in the finalCoordinates array
                        $finalCoordinates[$coordTrackKey] = array();
                    }

                    // Add the next posId in the finalCoordinates array and distance
                    $finalCoordinates[$coordTrackKey][] = array(
                        'postId' => $trackFilesKeys[$i + 1],
                        'nomExcursion' => $trackFiles[$trackFilesKeys[$i]]['nomExcursion'],
                        'distance' => $distance,
                        'lat' => $coordNextTrack['lat'],
                        'lon' => $coordNextTrack['lon'],
                    );

                    // Set the last track coordinates
                    $coordLastTrack = $coordNextTrack;
                    // Break the loop
                    break;
                }
            }
        }
    }

    // Get the upload directory
    $uploadDir = wp_upload_dir();

    // Get the path of the upload directory
    $uploadPath = $uploadDir['basedir'];

    // Create the json file
    $jsonFile = fopen($uploadPath . '/utileAPI/listMatchCoordinates.json', 'w');

    // Write the json in the file
    fwrite($jsonFile, $finalCoordinates);

    // Close the file
    fclose($jsonFile);
}

/**
 * @function convertGpxToJson
 * @description Convert the gpx file to json and upload it
 * @return array $response
 */
function convertGpxToJson()
{
    // Define the parameters
    $params = array(
        'limit' => -1,
    );

    // Get the upload directory
    $uploadDir = wp_upload_dir();

    // Get the path of the upload directory
    $uploadPath = $uploadDir['basedir'];

    //Define pods
    $podsSelect = pods('excursion', $params);

    if (0 < $podsSelect->total()) {
        // Loop through the excursions
        while ($podsSelect->fetch()) {
            // Get the name of the file
            $fileName = $podsSelect->field('track')[post_title] . '.gpx';

            // Search the file dans uploads directory
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploadPath));

            // Loop through the files
            foreach ($iterator as $file)
            {
                if ($file->isFile() && $file->getFilename() == $fileName) {
                    $filePath = $file->getPathname();
                    print_r($filePath);
                    break;
                }
            }

            // Verify if the file exists
            if (!$file->isFile()) {
                return new WP_Error('fichier_inexistant', 'Le fichier n\'existe pas', array('status' => 404));
            }

            // Copy the file in the tracks directory
            copy($filePath, $uploadPath . '/tracks/' . $fileName);

            // Convert the gpx file to json
            $json = formatGPXExcursions($file);

            // Get the name of the json file
            $jsonFileName = str_replace('.gpx', '.json', $fileName);

            // Create the json file
            $jsonFile = fopen($uploadPath . '/tracks/' . $jsonFileName, 'w');

            // Write the json in the file
            fwrite($jsonFile, $json);

            // Close the file
            fclose($jsonFile);
        }
    }
}

/**
 * @function convertGpxToJsonAndUploadListJson
 * @description This function is used to convert the gpx file to json and upload the json file to the server
 * @return void
 */
function convertGpxToJsonAndUploadListJson() {
    // Convert the gpx file to json
    convertGpxToJson();

    // Get the list of json tracks
    matchCoordinatesBetweenAllTracks();
}

/*===================================== BASE DE DONNEES =====================================*/
/**
 * @function insertSignalement
 * @description Insert a signalement in the database
 * @param $nom string The name of the signalement
 * @param $type string The type of the signalement
 * @param $description string The description of the signalement
 * @param $image string The image of the signalement
 * @param $latitude float The latitude of the signalement
 * @param $longitude float The longitude of the signalement
 * @param $post_id int The id of the excursion
 * @return idSignalement int The id of the signalement
 */
function  insertSignalementInBD(string $nom, string $type, string $description, string $image, float $latitude, float $longitude, int $post_id)
{
    // Get the database
    global $wpdb;

    if ($type !== 'Avertissement' && $type !== 'PointInteret') {
        return new WP_ERROR('type_incorrect', 'Le type doit être Avertissement ou PointInteret', array('status' => 400));
    }

    if (!get_post_status($post_id)) {
        return new WP_ERROR('post_inexistant', 'L\'excursion n\'existe pas', array('status' => 404));
    }

    // Prepare the execution of the query
    $signalement = $wpdb->prepare('INSERT INTO signalement (nom, type, description, image, lat, lon) VALUES (%s, %s, %s, %s, %f, %f)', $nom, $type, $description, $image, $latitude, $longitude);
    $wpdb->query($signalement);

    // Verify if the query is correct
    if ($wpdb->last_error !== '') {
        return new WP_ERROR('erreur_bdd', 'Erreur lors de l\'insertion du signalement', array('status' => 500));
    }

    // Get the id of the signalement
    $idSignalement = intval($wpdb->insert_id);
    $tableRelation = $wpdb->prepare('INSERT INTO post_signalement_relation (idSignalement, idExcursion) VALUES (%d, %d)', $idSignalement, $post_id);
    $wpdb->query($tableRelation);

    // Verify if the query is correct
    if ($wpdb->last_error !== '') {
        return new WP_ERROR('erreur_bdd', 'Erreur lors de l\'insertion de la relation du signalement', array('status' => 500));
    }

    // Return the id of the signalement
    return $idSignalement;
}

/**
 * @function insertRelation
 * @description Insert a relation between a signalement and an excursion
 * @param $idSignalement int The id of the signalement
 * @param $idExcursion int The id of the excursion
 * @return WP_ERROR|void
 */
function insertRelationInBD(int $idSignalement, int $idExcursion)
{
    // Get the database
    global $wpdb;

    // Prepare the execution of the query
    $relation = $wpdb->prepare('INSERT INTO post_signalement_relation (idSignalement, idExcursion) VALUES (%d, %d)', $idSignalement, $idExcursion);
    $wpdb->query($relation);

    // Verify if the query is correct
    if ($wpdb->last_error !== '') {
        return new WP_ERROR('erreur_bdd', 'Erreur lors de l\'insertion de la relation du signalement', array('status' => 500));
    }
}
