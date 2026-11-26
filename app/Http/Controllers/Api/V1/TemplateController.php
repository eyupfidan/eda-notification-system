<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTemplateRequest;
use App\Http\Resources\TemplateResource;
use App\Models\Template;
use App\Services\Templates\MissingTemplateVariables;
use App\Services\Templates\TemplateRenderer;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

#[Group('Templates', weight: 2)]
class TemplateController extends Controller
{
    /**
     * List templates.
     */
    public function index(): AnonymousResourceCollection
    {
        return TemplateResource::collection(Template::query()->orderBy('name')->paginate(50));
    }

    /**
     * Create a template.
     *
     * Subject and content may contain `{{ variable }}` placeholders (dot notation for nested
     * values, e.g. `{{ order.id }}`). Use it by sending `template_id` and `variables` instead of
     * `content` when creating notifications.
     */
    public function store(StoreTemplateRequest $request): JsonResponse
    {
        return TemplateResource::make(Template::create($request->validated()))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Get a template.
     */
    public function show(Template $template): TemplateResource
    {
        return TemplateResource::make($template);
    }

    /**
     * Update a template.
     *
     * Notifications already created keep the text they were rendered with.
     */
    public function update(StoreTemplateRequest $request, Template $template): TemplateResource
    {
        $template->update($request->validated());

        return TemplateResource::make($template);
    }

    /**
     * Delete a template.
     *
     * Existing notifications keep their rendered text; their `template_id` becomes null.
     */
    public function destroy(Template $template): Response
    {
        $template->delete();

        return response()->noContent();
    }

    /**
     * Preview a template.
     *
     * Renders the template with the given variables without creating a notification.
     */
    public function preview(Request $request, Template $template, TemplateRenderer $renderer): JsonResponse
    {
        $variables = $request->validate(['variables' => ['sometimes', 'array']])['variables'] ?? [];

        try {
            return response()->json(['data' => [
                'channel' => $template->channel,
                'subject' => $template->subject !== null ? $renderer->render($template->subject, $variables) : null,
                'content' => $renderer->render($template->content, $variables),
            ]]);
        } catch (MissingTemplateVariables $e) {
            throw ValidationException::withMessages(['variables' => $e->getMessage()]);
        }
    }
}
