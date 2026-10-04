<?php

namespace Base\Office\Controller\Admin;

use Base\Admin\Config\Action;
use Base\Admin\Config\Actions;

/**
 * The office's screens are written by the office - its coordination
 * (ROLE_ADMIN) or its staff (ROLE_STAFF: the agenda's screens) - not only by
 * the super-admin omnibase/admin asks by default for anything that writes.
 */
trait OpenToTrait
{
    protected function openTo(Actions $actions, string $role = 'ROLE_ADMIN', string ...$custom): Actions
    {
        return $actions->setPermissions(array_fill_keys(array_merge([
            Action::NEW, Action::EDIT, Action::DELETE, Action::BATCH_DELETE,
            Action::SAVE_AND_RETURN, Action::SAVE_AND_CONTINUE, Action::SAVE_AND_ADD_ANOTHER,
        ], $custom), $role));
    }
}
