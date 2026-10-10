<x-layouts.app title="Users">
    <x-slot:actions>
        <a href="{{ route('users.create') }}" class="btn btn-primary"><i class="ri-user-add-line me-1"></i>Add user</a>
    </x-slot:actions>

    <div class="card">
        <div class="card-body">
            <form method="GET" action="{{ route('users.index') }}" class="row g-2 mb-3">
                <div class="col-auto">
                    <label for="q" class="visually-hidden">Search</label>
                    <input type="search" id="q" name="q" value="{{ $q }}" class="form-control" placeholder="Name or email">
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-light">Search</button>
                </div>
            </form>

            <div class="table-responsive">
                <table class="table table-hover table-centered mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Added</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($users as $user)
                            <tr>
                                <td>{{ $user->name }}</td>
                                <td>{{ $user->email }}</td>
                                <td>{{ $user->role->label() }}</td>
                                <td>
                                    @if ($user->active)
                                        <span class="badge bg-success">Active</span>
                                    @else
                                        <span class="badge bg-secondary">Inactive</span>
                                    @endif
                                </td>
                                <td><x-date :value="$user->created_at" format="j M Y" /></td>
                                <td class="text-end">
                                    <a href="{{ route('users.edit', $user) }}" class="btn btn-sm btn-light">Edit</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-muted">No users match.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($users->hasPages())
                <div class="mt-3">{{ $users->links() }}</div>
            @endif
        </div>
    </div>
</x-layouts.app>
