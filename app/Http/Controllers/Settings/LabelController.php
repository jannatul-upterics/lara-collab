<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Label\StoreLabelRequest;
use App\Http\Requests\Label\UpdateLabelRequest;
use App\Http\Resources\Label\LabelResource;
use App\Models\Label;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class LabelController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(Label::class, 'label');
    }

    public function index(Request $request): Response
    {
        return Inertia::render('Settings/Labels/Index', [
            'items' => LabelResource::collection(
                Label::searchByQueryString()
                    ->sortByQueryString()
                    ->when($request->has('archived'), fn ($query) => $query->onlyArchived())
                    ->paginate(12)
            ),
        ]);
    }

    public function create()
    {
        return Inertia::render('Settings/Labels/Create');
    }

    public function store(StoreLabelRequest $request)
    {
        Label::create($request->validated());

        return redirect()->route('settings.labels.index')->success('Label created', 'A new label was successfully created.');
    }

    public function edit(Label $label)
    {
        return Inertia::render('Settings/Labels/Edit', ['item' => new LabelResource($label)]);
    }

    public function update(Label $label, UpdateLabelRequest $request)
    {
        $label->update($request->validated());

        return redirect()->route('settings.labels.index')->success('Label updated', 'The label was successfully updated.');
    }

    public function destroy(Label $label)
    {
        $label->archive();

        return redirect()->back()->success('Label archived', 'The label was successfully archived.');
    }

    public function restore(int $labelId)
    {
        $label = Label::withArchived()->findOrFail($labelId);

        $this->authorize('restore', $label);

        $label->unArchive();

        return redirect()->back()->success('Label restored', 'The restoring of the label was completed successfully.');
    }

    public function permanentDelete(int $labelId)
    {
        try {
            return DB::transaction(function () use ($labelId) {
                $label = Label::withArchived()->lockForUpdate()->findOrFail($labelId);

                $this->authorize('forceDelete', $label);

                if (! $label->isArchived()) {
                    return redirect()->back()->warning('Action stopped', 'The label is not archived. Only archived labels can be permanently deleted.');
                }

                // Detach all tasks from label_task pivot
                DB::table('label_task')->where('label_id', $label->id)->delete();

                $labelName = $label->name;
                $label->delete();

                Log::info("Label #{$labelId} ('{$labelName}') permanently deleted by user #" . auth()->id());

                return redirect()->back()->success('Label deleted', 'The label was permanently deleted.');
            });
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            throw $e;
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            throw $e;
        } catch (\Illuminate\Database\QueryException $e) {
            Log::error("Failed to permanently delete label #{$labelId}: " . $e->getMessage());
            return redirect()->back()->warning('Action stopped', 'This label cannot be permanently deleted because of dependent records.');
        } catch (\Exception $e) {
            Log::error("Failed to permanently delete label #{$labelId}: " . $e->getMessage());
            return redirect()->back()->warning('Action stopped', 'An error occurred while deleting the label: ' . $e->getMessage());
        }
    }
}
