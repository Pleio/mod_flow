<?php
require_once(dirname(__FILE__) . "/../../vendor/autoload.php");

elgg_register_event_handler('init', 'system', 'flow_init');

function flow_init() {
    elgg_register_action('flow/settings/save', dirname(__FILE__) . '/actions/admin/settings/save.php', 'admin');
    elgg_register_event_handler('create', 'object', 'flow_create_object');
    elgg_register_page_handler('flow', 'flow_page_handler');
    elgg_register_plugin_hook_handler('public_pages', 'walled_garden', 'flow_public_pages');
}

function flow_create_object($event, $object_type, $object) {
    global $DISABLE_FLOW_LOGGING;

    if ($DISABLE_FLOW_LOGGING) {
        return;
    }

    $url = elgg_get_plugin_setting('url', 'flow');
    $casetype = elgg_get_plugin_setting('casetype', 'flow');
    $token = elgg_get_plugin_setting('token', 'flow');
    $subtypes = json_decode(elgg_get_plugin_setting('subtypes', 'flow'));

    if (!$object || !$url || !$casetype || !$subtypes || !$token) {
        return;
    }

    if (in_array($object->getSubtype(), ["answer", "comment"])) {
        $container = $object->getContainerEntity();
        if ($container instanceof ElggObject && in_array($container->getSubtype(), $subtypes)) {
            return flow_create_comment($event, $object_type, $object);
        }
    }

    if (!in_array($object->getSubtype(), $subtypes)) {
        return;
    }

    $client = new \GuzzleHttp\Client();

    $headers = [
        'Authorization' => "Token {$token}",
        'Accept' => 'application/json'
    ];

    $description = "
        {$object->description}
        <br /><br /><a href=\"{$object->getURL()}\">{$object->getURL()}</a>
    ";

    try {
        $response = $client->request('POST', $url . "api/cases/", [
            'headers' => $headers,
            'timeout' => 2,
            'json' => [
                'casetype' => $casetype,
                'name' => $object->title ?: elgg_echo('flow:no_title'),
                'description' => $description,
                'external_id' => $object->guid,
                'tags' => []
            ]
        ]);

        $body = json_decode($response->getBody());
        if ($body && $body->id) {
            $object->flow_id = $body->id;
            $object->save();
        }
    } catch (Exception $e) {
        elgg_log("Could not write case to Flow: {$e->getMessage()}" , 'ERROR');
    }
}

function flow_create_comment($event, $object_type, $object) {
    $url = elgg_get_plugin_setting('url', 'flow');
    $token = elgg_get_plugin_setting('token', 'flow');
    if (!$url || !$token) {
        return;
    }

    $container = $object->getContainerEntity();
    if (!$container->flow_id) {
        return;
    }

    $owner = $object->getOwnerEntity();

    $client = new \GuzzleHttp\Client([
        'request.options' => [
            'exceptions' => false
        ]
    ]);

    $headers = [
        'Authorization' => "Token {$token}",
        'Accept' => 'application/json'
    ];

    try {
        $response = $client->request('POST', $url . "api/externalcomments/", [
            'headers' => $headers,
            'timeout' => 2,
            'json' => [
                'case' => $container->flow_id,
                'author' => $owner->name,
                'description' => $object->description
            ]
        ]);
    } catch (Exception $e) {
        elgg_log("Could not write comment to Flow: {$e->getMessage()}" , 'ERROR');
    }
}

function flow_public_pages($hook, $type, $return_value, $param) {
    // API endpoint handles it's own authentication, do not let it block by walled garden.
    $return_value[] = 'flow/.*';
    return $return_value;
}

function flow_page_handler($page) {
    header("Content-Type: application/json");

    flow_validate_api_request();
    flow_switch_user();

    switch ($page[0]) {
        case "comments":
            switch ($page[1]) {
                case "add":
                    flow_add_comment($_POST);
                    return true;
                    break;
            }
            break;
    }

    http_response_code(404);
    echo json_encode([ 'error' => 'Could not find this page. ']);
    exit();
}

function flow_validate_api_request() {
    $token = elgg_get_plugin_setting('token', 'flow');
    if (!$token) {
        echo json_encode([ 'error' => 'Access token not set in flow plugin config.' ]);
        exit();
    }

    $header = null;
    if (isset($_SERVER["Authorization"])) {
        $header = trim($_SERVER["Authorization"]);
    } elseif (isset($_SERVER["HTTP_AUTHORIZATION"])) {
        $header = trim($_SERVER["HTTP_AUTHORIZATION"]);
    } elseif (function_exists("apache_request_headers")) {
        $apache_headers = apache_request_headers();
        $header = trim($apache_headers["Authorization"]);
    }

    preg_match('/Bearer\s(\S+)/', $header, $matches);
    if (!$matches[1]) {
        http_response_code(401);
        echo json_encode([ 'error' => 'Bearer token not set in authorization header.' ]);
        exit();
    }

    if ($matches[1] !== $token) {
        http_response_code(403);
        echo json_encode([ 'error' => 'Invalid bearer token set.' ]);
        exit();
    }
}

function flow_switch_user() {
    $user_guid = elgg_get_plugin_setting('user_guid', 'flow');
    if (!$user_guid) {
        http_response_code(401);
        echo json_encode([ 'error' => 'No user_guid configured in flow plugin config.' ]);
        exit();
    }

    $user = get_entity($user_guid);
    if (!$user) {
        http_response_code(403);
        echo json_encode([ 'error' => 'Could not find user attached to user_guid in flow plugin config.' ]);
        exit();
    }

    login($user);
}

function flow_add_comment($data) {
    global $DISABLE_FLOW_LOGGING;

    if (!$data['description']) {
        http_response_code(400);
        echo json_encode([ 'error' => 'The variable description is not set.' ]);
        exit();
    }

    if (!$data['container_guid']) {
        http_response_code(400);
        echo json_encode([ 'error' => 'The variable container_guid is not set.' ]);
        exit();
    }

    $container = get_entity($data['container_guid']);
    if (!$container) {
        http_response_code(400);
        echo json_encode([ 'error' => 'Could not find container of guid.' ]);
        exit();
    }

    $DISABLE_FLOW_LOGGING = true;

    $access_id = $container->access_id;
    if ($container->access_id == ACCESS_PRIVATE) {
        $access_id = ACCESS_LOGGED_IN;
    }

    $comment = new ElggObject();
    $comment->subtype = ($container->getSubtype() === "question") ? "answer" : "comment";
    $comment->description = $data["description"];
    $comment->container_guid = $container->guid;
    $comment->access_id = $access_id;
    $comment->save();

    update_entity_last_action($container->guid, $comment->time_created);

    $view = "river/object/{$comment->subtype}/create";
    add_to_river($view, 'create', elgg_get_logged_in_user_guid(), $comment->guid);

    $DISABLE_FLOW_LOGGING = false;

    echo json_encode([
        'error' => null,
        'status' => 'Created the requested comment.'
    ]);
}