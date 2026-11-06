<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Assistant\AssistantBoard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** "My assistant": everything a director needs today on one page. Sends nothing. */
class AssistantController extends Controller
{
    public function index(Request $request, AssistantBoard $board): View
    {
        abort_unless($request->user()->isAdmin(), 403);

        return view('admin.assistant.index', ['b' => $board->build()]);
    }

    public function saveMoney(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $data = $request->validate([
            'ltd' => ['nullable', 'numeric', 'between:-100000,1000000'],
            'pvt' => ['nullable', 'numeric', 'between:-100000,1000000'],
            'incoming' => ['nullable', 'numeric', 'between:0,1000000'],
            'owed_to_us' => ['nullable', 'string', 'max:3000'],
            'we_owe' => ['nullable', 'string', 'max:3000'],
        ]);
        AssistantBoard::saveMoney($data + ['ltd' => null, 'pvt' => null, 'incoming' => null], $request->user());

        return redirect()->route('assistant.index')->with('status', 'Money board saved.');
    }

    public function addTodo(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $data = $request->validate(['text' => ['required', 'string', 'max:200'], 'due' => ['nullable', 'date_format:Y-m-d']]);
        AssistantBoard::addTodo($data['text'], $data['due'] ?? null, $request->user());

        return redirect()->route('assistant.index', ['#todo'])->with('status', 'Added.');
    }

    public function toggleTodo(Request $request, string $id): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        AssistantBoard::toggleTodo($id);

        return redirect()->route('assistant.index', ['#todo']);
    }

    public function deleteTodo(Request $request, string $id): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        AssistantBoard::deleteTodo($id);

        return redirect()->route('assistant.index', ['#todo']);
    }
}
