<?php

declare(strict_types=1);

namespace Pollora\Role\Tests\Fixtures;

use Pollora\Attributes\ModifyRole;
use Pollora\Attributes\Role;
use Pollora\Attributes\Role\Grants;
use Pollora\Attributes\Role\GrantsPostType;
use Pollora\Attributes\Role\GrantsTaxonomy;
use Pollora\Attributes\Role\Without;

#[Role('editor')]
final class RedeclaresCoreRole {}

#[Role('boss', inherits: 'administrator')]
final class InheritsSuperRole {}

#[Role('meddler')]
#[Grants('edit_users')]
final class GrantsSensitive {}

#[ModifyRole('author')]
#[Grants('install_plugins')]
final class ModifiesWithSensitive {}

#[Role('undecided')]
#[Grants('read')]
#[Without('read')]
final class GrantsAndRemoves {}

#[Role('both')]
#[ModifyRole('author')]
final class RoleAndModify {}

final class NoRoleAttribute {}

#[Role('lost')]
#[GrantsPostType(Article::class)]
final class GrantsSharedPostType {}

#[Role('wrong_post_type')]
#[GrantsPostType(EventCap::class)]
final class GrantsNotAPostType {}

#[Role('shared_tax')]
#[GrantsTaxonomy(Topic::class)]
final class GrantsSharedTaxonomy {}

#[Role('wrong_tax')]
#[GrantsTaxonomy(Event::class)]
final class GrantsNotATaxonomy {}

#[Role('orphan', inherits: NoRoleAttribute::class)]
final class InheritsNotARole {}

#[Role('event_manager')]
final class DuplicateEventManager {}

#[Role('loop_a', inherits: 'loop_b')]
final class LoopA {}

#[Role('loop_b', inherits: 'loop_a')]
final class LoopB {}

#[ModifyRole('editor')]
#[Grants('edit_theme_options')]
final class ContradictsEditorAdjustments {}

#[ModifyRole('editor')]
#[Grants('moderate_comments')]
final class AlsoAdjustsEditor {}
