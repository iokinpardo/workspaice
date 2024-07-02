<div class="max-w-md mx-auto bg-white rounded-xl shadow-md overflow-hidden md:max-w-2xl m-4">
    <div class="p-8">
        <h2 class="text-2xl font-bold mb-4">Create New Project</h2>
        <form wire:submit.prevent="createProject">
            <div class="mb-4">
                <label for="name" class="block text-gray-700 text-sm font-bold mb-2">Project Name</label>
                <input type="text" id="name" wire:model="name" class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline">
                @error('name') <span class="text-red-500 text-xs">{{ $message }}</span> @enderror
            </div>
            <div class="mb-4">
                <label for="description" class="block text-gray-700 text-sm font-bold mb-2">Description</label>
                <textarea id="description" wire:model="description" class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" rows="3"></textarea>
                @error('description') <span class="text-red-500 text-xs">{{ $message }}</span> @enderror
            </div>
            <div class="flex items-center justify-between">
                <button type="submit" class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded focus:outline-none focus:shadow-outline">
                    Create Project
                </button>
            </div>
        </form>
    </div>
</div>