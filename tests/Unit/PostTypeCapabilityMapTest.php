<?php

declare(strict_types=1);

use Pollora\Role\Domain\Enums\Access;
use Pollora\Role\Domain\Services\PostTypeCapabilityMap;

it('names the capabilities of each level like get_post_type_capabilities()', function (Access $access, array $expected): void {
    expect((new PostTypeCapabilityMap)->capabilities('event', $access))->toBe($expected);
})->with([
    'contributor' => [Access::Contributor, ['edit_events', 'delete_events']],
    'author' => [Access::Author, ['edit_events', 'delete_events', 'publish_events', 'edit_published_events', 'delete_published_events']],
    'editor' => [Access::Editor, [
        'edit_events', 'delete_events', 'publish_events', 'edit_published_events', 'delete_published_events',
        'edit_others_events', 'delete_others_events', 'read_private_events', 'edit_private_events', 'delete_private_events',
    ]],
]);

it('uses the names mapped by #[Capabilities]', function (): void {
    expect((new PostTypeCapabilityMap)->capabilities('venue', Access::Author, ['publish_posts' => 'open_venues']))
        ->toBe(['edit_venues', 'delete_venues', 'open_venues', 'edit_published_venues', 'delete_published_venues']);
});
