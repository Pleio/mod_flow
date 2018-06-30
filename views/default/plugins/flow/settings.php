<?php
$settings = $vars["entity"];

$options = [
    "blog" => "blog",
    "news" => "news",
    "question" => "question",
    "discussion" => "discussion"
];

echo "<div>";
echo "<label>" . elgg_echo("flow:settings:objects") . "</label>";
echo elgg_view("input/checkboxes", [
    "name" => "params[subtypes]",
    "options" => $options,
    "value" => $settings->subtypes ? json_decode($settings->subtypes) : null
]);
echo "</div>";

echo "<div>";
echo "<label>" . elgg_echo("flow:settings:url") . "</label>";
echo elgg_view("input/text", [
    "name" => "params[url]",
    "value" => $settings->url
]);
echo "<span class=\"elgg-subtext\">" . elgg_echo("flow:settings:url:explanation") . "</span>";
echo "</div>";

echo "<div>";
echo "<label>" . elgg_echo("flow:settings:token") . "</label>";
echo elgg_view("input/text", [
    "name" => "params[token]",
    "value" => $settings->token
]);
echo "</div>";

echo "<div>";
echo "<label>" . elgg_echo("flow:settings:casetype") . "</label>";
echo elgg_view("input/text", [
    "name" => "params[casetype]",
    "value" => $settings->casetype
]);
echo "<span class=\"elgg-subtext\">" . elgg_echo("flow:settings:casetype:explanation") . "</span>";
echo "</div>";

echo "<div>";
echo "<label>" . elgg_echo("flow:settings:user_guid") . "</label>";
echo elgg_view("input/text", [
    "name" => "params[user_guid]",
    "value" => $settings->user_guid
]);
echo "<span class=\"elgg-subtext\">" . elgg_echo("flow:settings:user_guid:explanation") . "</span>";
echo "</div>";
