<div>
    @if ($invitations->count() > 0)
        <h2 class="pb-2">Pending Invitations</h2>
        <div class="flex flex-col">
            <div class="flex flex-col">
                <div class="overflow-x-auto">
                    <div class="inline-block min-w-full">
                        <div class="overflow-hidden">
                            <table class="min-w-full divide-y divide-coolgray-400">
                                <thead>
                                    <tr class="text-neutral-500">
                                        <th class="px-5 py-3 text-xs font-medium text-left uppercase">Email
                                        </th>
                                        <th class="px-5 py-3 text-xs font-medium text-left uppercase">
                                            Via</th>
                                        <th class="px-5 py-3 text-xs font-medium text-left uppercase">Role</th>
                                        <th class="px-5 py-3 text-xs font-medium text-left uppercase">Invitation Link
                                        </th>
                                        <th class="px-5 py-3 text-xs font-medium text-left uppercase">Actions
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-coolgray-400">
                                    @foreach ($invitations as $invite)
                                        <tr class="text-white bg-coolblack hover:bg-coolgray-100/40">
                                            <td class="px-5 py-4 text-sm whitespace-nowrap">{{ $invite->email }}</td>
                                            <td class="px-5 py-4 text-sm whitespace-nowrap">{{ $invite->via }}</td>
                                            <td class="px-5 py-4 text-sm whitespace-nowrap">{{ $invite->role }}</td>
                                            <td class="px-5 py-4 text-sm whitespace-nowrap" x-data="checkProtocol">
                                                <template x-if="isHttps">
                                                    <x-forms.button
                                                        x-on:click="copyToClipboard('{{ $invite->link }}')">Copy
                                                        Invitation
                                                        Link</x-forms.button>
                                                </template>
                                                <x-forms.input id="null" type="password"
                                                    value="{{ $invite->link }}" />
                                            </td>
                                            <td class="px-5 py-4 text-sm whitespace-nowrap">
                                                <x-forms.button
                                                    wire:click.prevent='deleteInvitation({{ $invite->id }})'>Revoke
                                                    Invitation
                                                </x-forms.button>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>

@script
    <script>
        Alpine.data('checkProtocol', () => {
            return {
                isHttps: window.location.protocol === 'https:'
            }
        })
    </script>
@endscript
