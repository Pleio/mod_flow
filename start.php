<?php
require_once(dirname(__FILE__) . "/../../vendor/autoload.php");

elgg_register_event_handler('init', 'system', 'flow_init');

function flow_init() {
    elgg_register_action('flow/settings/save', dirname(__FILE__) . '/actions/admin/settings/save.php', 'admin');
    elgg_register_event_handler('create', 'object', 'flow_create_object');
}

function flow_create_object($event, $object_type, $object) {
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

        <a href=\"{$object->getURL()}\">{$object->getURL()}</a>
    ";

    try {
        $response = $client->request('POST', $url . "api/cases/", [
            'headers' => $headers,
            'json' => [
                'casetype' => $casetype,
                'name' => $object->title ?: elgg_echo('flow:no_title'),
                'description' => $description,
                'tags' => []
            ]
        ]);

        $body = json_decode($response->getBody());
        if ($body && $body->id) {
            $object->flow_id = $body->id;
            $object->save();
        }
    } catch (\GuzzleHttp\Exception\BadResponseException $e) {
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

    $client = new \GuzzleHttp\Client();

    $headers = [
        'Authorization' => "Token {$token}",
        'Accept' => 'application/json'
    ];

    try {
        $response = $client->request('POST', $url . "api/caselogs/", [
            'headers' => $headers,
            'json' => [
                'case' => $container->flow_id,
                'event' => 'external_comment'
            ]
        ]);
    } catch (\GuzzleHttp\Exception\BadResponseException $e) {
        elgg_log("Could not write comment to Flow: {$e->getMessage()}" , 'ERROR');
    }
}
