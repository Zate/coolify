<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project as ModelsProject;
use Illuminate\Http\Request;

class APIProject extends Controller
{
    public function projects(Request $request)
    {
        $teamId = get_team_id_from_token();
        if (is_null($teamId)) {
            return response()->json(['error' => 'Invalid token.', 'docs' => 'https://coolify.io/docs/api/authentication'], 400);
        }
        $projects = ModelsProject::whereTeamId($teamId)->select('id', 'name', 'uuid')->get();
        return response()->json($projects);
    }
    public function project_by_uuid(Request $request)
    {
        $teamId = get_team_id_from_token();
        if (is_null($teamId)) {
            return response()->json(['error' => 'Invalid token.', 'docs' => 'https://coolify.io/docs/api/authentication'], 400);
        }
        $project = ModelsProject::whereTeamId($teamId)->whereUuid(request()->uuid)->first()->load(['environments']);
        return response()->json($project);
    }
    public function environment_details(Request $request)
    {
        $teamId = get_team_id_from_token();
        if (is_null($teamId)) {
            return response()->json(['error' => 'Invalid token.', 'docs' => 'https://coolify.io/docs/api/authentication'], 400);
        }
        $project = ModelsProject::whereTeamId($teamId)->whereUuid(request()->uuid)->first();
        $environment = $project->environments()->whereName(request()->environment_name)->first()->load(['applications', 'postgresqls', 'redis', 'mongodbs', 'mysqls', 'mariadbs', 'services']);
        return response()->json($environment);
    }
}
