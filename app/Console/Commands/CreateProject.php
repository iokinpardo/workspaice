<?php

namespace App\Console\Commands;

use App\Models\Project;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CreateProject extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'project:create';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new project';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $name = $this->ask('What is the name of the project?', 'New Project');
        $slug = Str::slug($name);
        $description = $this->ask('Enter the description of the project?') ?? '';
        $technical_specs = $this->ask('What are the technical specs of the project?') ?? '';
        $system_description = $this->ask('Enter system info') ?? '';

        $homeDir = getenv('HOME');
        $suggestedPath = "{$homeDir}/projects/{$slug}";

        $full_path = $this->ask('What is the full path of the project? (if the directory does not exist, it will be created)', $suggestedPath);
        $full_path = rtrim($full_path, '/');
        if (!is_dir($full_path)) {
            mkdir($full_path, 0777, true);
        }

        $tasks = "1 - Scan the project directory and understand the structure
        2 - Review the project information and understand the requirements
        3 - Welcome the user and ask clarifying questions and rewrite the requirements as needed
        4 - Once enough detail has been collected, create a final task list to replace this one and begin working.";

        $project = new Project();
        $project->name = $name;
        $project->description = $description;
        $project->technical_specs = $technical_specs;
        $project->system_description = $system_description;
        $project->full_path = $full_path;
        $project->slug = $slug;
        $project->notes = "";
        $project->tasks = $tasks;
        $project->save();

        $this->info('Project created successfully.');
    }
}
