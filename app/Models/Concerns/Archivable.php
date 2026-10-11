<?php

namespace App\Models\Concerns;

/**
 * Archive instead of delete: archived_at hides a record from the admin's
 * default list and it can be restored. Archiving also switches it off
 * ($archivedStatus) so nothing guest-facing offers it; restoring brings it
 * back switched off ($restoredStatus) for the admin to re-enable.
 *
 * Named archiveRecord()/unarchive() so they never collide with
 * SoftDeletes::restore() on models that also use soft deletes.
 */
trait Archivable
{
    public function initializeArchivable(): void
    {
        $this->casts['archived_at'] = 'datetime';
    }

    public function scopeNotArchived($query)
    {
        return $query->whereNull($this->getTable() . '.archived_at');
    }

    public function scopeArchived($query)
    {
        return $query->whereNotNull($this->getTable() . '.archived_at');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function archiveRecord(): void
    {
        $this->forceFill(['archived_at' => now(), 'status' => static::ARCHIVED_STATUS])->save();
    }

    public function unarchive(): void
    {
        $this->forceFill(['archived_at' => null, 'status' => static::RESTORED_STATUS])->save();
    }
}
