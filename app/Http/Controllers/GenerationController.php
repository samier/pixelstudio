<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreGenerationRequest;
use App\Http\Resources\GenerationResource;
use App\Models\Generation;
use App\Services\Billing\InsufficientCreditsException;
use App\Services\Generations\GenerationService;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PixelforgeException;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GenerationController extends Controller
{
    public function __construct(private readonly GenerationService $generations) {}

    /** The generate page: prompt form with a live cost estimate, plus the latest generations. */
    public function create(Request $request, PixelforgeClient $pixelforge): Response
    {
        try {
            $pricing = $pixelforge->pricing()->data;
        } catch (PixelforgeException) {
            $pricing = null; // The page explains that prices can't be shown right now and disables submit.
        }

        $recent = $request->user()->generations()->latest('id')->limit(6)->get();

        return Inertia::render('Generate', [
            'pricing' => $pricing,
            'markupPercent' => config('billing.markup_percent'),
            'limits' => [
                'sizes' => StoreGenerationRequest::SIZES,
                'qualities' => StoreGenerationRequest::QUALITIES,
                'maxPromptLength' => StoreGenerationRequest::MAX_PROMPT_LENGTH,
                'maxReferenceImages' => StoreGenerationRequest::MAX_REFERENCE_IMAGES,
            ],
            'recent' => GenerationResource::collection($recent),
        ]);
    }

    public function index(Request $request): Response
    {
        $generations = $request->user()->generations()
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->latest('id')
            ->paginate(12)
            ->withQueryString();

        return Inertia::render('History', [
            'generations' => GenerationResource::collection($generations),
            'filters' => ['status' => $request->query('status')],
        ]);
    }

    public function store(StoreGenerationRequest $request): RedirectResponse
    {
        return $this->submit($request, $request->validated());
    }

    /** Submit a new generation with the same settings as an earlier one (e.g. after a failure). */
    public function retry(Request $request, Generation $generation): RedirectResponse
    {
        abort_unless($generation->user_id === $request->user()->id, 404);

        return $this->submit($request, $generation->only(['prompt', 'size', 'quality', 'reference_images']));
    }

    private function submit(Request $request, array $input): RedirectResponse
    {
        try {
            $this->generations->create($request->user(), $input);
        } catch (InsufficientCreditsException $e) {
            return back()->withErrors(['credits' => sprintf(
                'This generation can cost up to $%s, but your balance is $%s. Buy credits to continue.',
                Money::display($e->requiredNanos), Money::display($e->availableNanos),
            )]);
        } catch (PixelforgeException) {
            return back()->withErrors(['prompt' => 'We could not load Pixelforge prices, so we can\'t price this yet. Please try again in a moment.']);
        }

        return back()->with('success', 'Generation queued. You\'ll see it update below.');
    }
}
