<?php

namespace App\Actions\Employees;

use App\Enums\WorkMode;
use App\Models\Country;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\EmployeeSpouse;
use App\Models\EmployeeWorkExperience;
use App\Support\FileBatch;
use App\Support\PhoneNumbers;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Arr;

/**
 * Writes validated form data (EmployeeRules) onto a new or existing employee, including the repeating
 * sections and attached uploads. Only the keys present in $data are touched. The caller owns the
 * transaction (FileBatch::transaction) and decides whether completeness is enforced.
 */
class SaveEmployee
{
    private const PHONE_FIELDS = ['phone', 'secondary_phone', 'landline', 'emergency_contact_phone'];

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Employee $employee, array $data, FileBatch $files): void
    {
        $employee->fill(Arr::only($data, Employee::FORM_FIELDS));

        if (array_key_exists('work_mode', $data) && $data['work_mode'] === null) {
            $employee->work_mode = WorkMode::Onsite;
        }
        $this->normalisePhones($employee, $data);
        $this->normaliseRemoteAccess($employee);

        $isNew = ! $employee->exists;
        $employee->save();

        if (array_key_exists('avatar_upload_id', $data)) {
            $this->replaceAvatar($employee, $data['avatar_upload_id'], $files);
        }

        if (array_key_exists('spouses', $data)) {
            $this->syncSpouses($employee, (array) $data['spouses']);
        }

        if (array_key_exists('qualifications', $data)) {
            $this->sync($employee->qualifications(), (array) $data['qualifications'], function (Model $row, array $item) {
                $row->fill(Arr::only($item, ['institution', 'degree_program_id', 'start_date', 'end_date', 'year']));
            });
        }

        // Turning the toggle off removes the experiences; while it's off none can be added (EmployeeRules).
        if (! $employee->has_work_experience && ! $isNew) {
            $employee->workExperiences()->get()->each(fn (EmployeeWorkExperience $w) => $this->deleteExperience($w, $files));
        } elseif (array_key_exists('work_experiences', $data)) {
            $this->syncExperiences($employee, (array) $data['work_experiences'], $files);
        }

        if (array_key_exists('documents', $data)) {
            $this->syncDocuments($employee, (array) $data['documents'], $files);
        }
    }

    /** Deletes every stored file of an employee (used when a draft is discarded). */
    public function deleteAllFiles(Employee $employee, FileBatch $files): void
    {
        $files->deleteAfterCommit('public', $employee->avatar_path);
        foreach ($employee->workExperiences()->get(['id', 'experience_letter_path']) as $experience) {
            $files->deleteAfterCommit('local', $experience->experience_letter_path);
        }
        foreach ($employee->documents()->get(['id', 'path']) as $document) {
            $files->deleteAfterCommit('local', $document->path);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function normalisePhones(Employee $employee, array $data): void
    {
        $country = $employee->country_id ? Country::query()->find($employee->country_id, ['id', 'iso2']) : null;

        foreach (self::PHONE_FIELDS as $field) {
            // A changed country re-formats every stored number too.
            if (! array_key_exists($field, $data) && ! $employee->isDirty('country_id')) {
                continue;
            }
            $value = $employee->getAttribute($field);
            if (is_string($value) && $value !== '') {
                $employee->setAttribute($field, PhoneNumbers::toE164($value, $country?->iso2) ?? $value);
            }
        }
    }

    private function normaliseRemoteAccess(Employee $employee): void
    {
        if (! $employee->remote_access) {
            $employee->permanent_remote_access = false;
            $employee->remote_access_from = null;
            $employee->remote_access_until = null;
        } elseif ($employee->permanent_remote_access) {
            $employee->remote_access_until = null;
        }
    }

    private function replaceAvatar(Employee $employee, ?string $uploadId, FileBatch $files): void
    {
        $files->deleteAfterCommit('public', $employee->avatar_path);
        $employee->avatar_path = $uploadId
            ? $files->claim($uploadId, 'public', "avatars/{$employee->id}")['path']
            : null;
        $employee->save();
    }

    /**
     * @param  array<int, mixed>  $spouses
     */
    private function syncSpouses(Employee $employee, array $spouses): void
    {
        $this->sync($employee->spouses(), $spouses, function (Model $spouse, array $item) {
            $spouse->fill(Arr::only($item, ['full_name', 'cnic', 'birth_date']));
        }, function (Model $spouse, array $item) {
            /** @var EmployeeSpouse $spouse */
            if (array_key_exists('children', $item)) {
                $this->sync($spouse->children(), (array) $item['children'], function (Model $child, array $c) {
                    $child->fill(Arr::only($c, ['full_name', 'birth_date']));
                });
            }
        });
    }

    /**
     * @param  array<int, mixed>  $items
     */
    private function syncExperiences(Employee $employee, array $items, FileBatch $files): void
    {
        $this->sync($employee->workExperiences(), $items, function (Model $row, array $item) use ($files) {
            /** @var EmployeeWorkExperience $row */
            $row->fill(Arr::except($item, ['id', 'experience_letter_upload_id']));

            if (is_string($row->contact_person_phone) && $row->contact_person_phone !== '' && $row->isDirty(['contact_person_phone', 'country_id'])) {
                $iso2 = $row->country_id ? Country::query()->whereKey($row->country_id)->value('iso2') : null;
                $row->contact_person_phone = PhoneNumbers::toE164($row->contact_person_phone, $iso2) ?? $row->contact_person_phone;
            }

            if (array_key_exists('experience_letter_upload_id', $item)) {
                $files->deleteAfterCommit('local', $row->experience_letter_path);
                $letter = $item['experience_letter_upload_id']
                    ? $files->claim($item['experience_letter_upload_id'], 'local', "employees/{$row->employee_id}/experience-letters")
                    : null;
                $row->experience_letter_path = $letter['path'] ?? null;
                $row->experience_letter_name = $letter['name'] ?? null;
                $row->experience_letter_mime = $letter['mime_type'] ?? null;
                $row->experience_letter_size = $letter['size'] ?? null;
            }
        }, deleting: fn (Model $row) => $files->deleteAfterCommit('local', $row->getAttribute('experience_letter_path')));
    }

    /**
     * @param  array<int, mixed>  $items
     */
    private function syncDocuments(Employee $employee, array $items, FileBatch $files): void
    {
        $this->sync($employee->documents(), $items, function (Model $row, array $item) use ($files) {
            /** @var EmployeeDocument $row */
            if (array_key_exists('title', $item)) {
                $row->title = $item['title'];
            }
            if (! empty($item['upload_id'])) {
                $files->deleteAfterCommit('local', $row->getOriginal('path'));
                $file = $files->claim($item['upload_id'], 'local', "employees/{$row->employee_id}/documents");
                $row->path = $file['path'];
                $row->original_name = $file['name'];
                $row->mime_type = $file['mime_type'];
                $row->size = $file['size'];
            }
        }, deleting: fn (Model $row) => $files->deleteAfterCommit('local', $row->getAttribute('path')));
    }

    private function deleteExperience(EmployeeWorkExperience $experience, FileBatch $files): void
    {
        $files->deleteAfterCommit('local', $experience->experience_letter_path);
        $experience->delete();
    }

    /**
     * Makes the related rows match $items in order: items with an id update that row, the rest are
     * created, and rows not mentioned are deleted. Only keys present in an item are written.
     *
     * @template TModel of Model
     *
     * @param  HasMany<TModel, covariant Model>  $relation
     * @param  array<int, mixed>  $items
     * @param  callable(TModel, array<string, mixed>): void  $fill
     * @param  (callable(TModel, array<string, mixed>): void)|null  $saved  runs after the row is saved
     * @param  (callable(TModel): void)|null  $deleting
     */
    private function sync(HasMany $relation, array $items, callable $fill, ?callable $saved = null, ?callable $deleting = null): void
    {
        $existing = $relation->get()->keyBy('id');
        $keep = [];

        foreach (array_values($items) as $index => $item) {
            $item = (array) $item;
            $row = isset($item['id']) ? $existing->get((int) $item['id']) : null;
            $row ??= $relation->make();

            $fill($row, $item);
            $row->setAttribute('sort_order', $index);
            $row->save();
            $keep[] = $row->getKey();

            if ($saved) {
                $saved($row, $item);
            }
        }

        foreach ($existing as $id => $row) {
            if (! in_array($id, $keep, true)) {
                if ($deleting) {
                    $deleting($row);
                }
                $row->delete();
            }
        }
    }
}
