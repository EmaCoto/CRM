@props([
    'endpoint',
    'entityType',
    'entityId',
    'phoneNumber',
    'contactName' => '',
    'participantPersonId' => null,
    'label' => trans('admin::app.integrations.zadarma.call.button'),
    'buttonClass' => 'secondary-button',
])

@if ($phoneNumber)
    <v-zadarma-call-button
        endpoint="{{ $endpoint }}"
        entity-type="{{ $entityType }}"
        :entity-id='@json($entityId)'
        phone-number="{{ $phoneNumber }}"
        contact-name="{{ $contactName }}"
        :participant-person-id='@json($participantPersonId)'
        label="{{ $label }}"
        button-class="{{ $buttonClass }}"
    ></v-zadarma-call-button>
@endif

@pushOnce('scripts')
    <script
        type="text/x-template"
        id="v-zadarma-call-button-template"
    >
        <button
            type="button"
            :class="buttonClass"
            :disabled="isCalling"
            @click="confirmCall"
        >
            <span
                v-if="isCalling"
                class="icon-loading animate-spin text-base"
            ></span>

            <span
                v-else
                class="icon-call text-base"
            ></span>

            @{{ label }}
        </button>
    </script>

    <script type="module">
        app.component('v-zadarma-call-button', {
            template: '#v-zadarma-call-button-template',

            props: {
                endpoint: {
                    type: String,
                    required: true,
                },

                entityType: {
                    type: String,
                    required: true,
                },

                entityId: {
                    type: [Number, String],
                    required: true,
                },

                phoneNumber: {
                    type: String,
                    required: true,
                },

                contactName: {
                    type: String,
                    default: '',
                },

                participantPersonId: {
                    type: [Number, String],
                    default: null,
                },

                label: {
                    type: String,
                    default: "@lang('admin::app.integrations.zadarma.call.button')",
                },

                buttonClass: {
                    type: String,
                    default: 'secondary-button',
                },
            },

            data() {
                return {
                    isCalling: false,
                };
            },

            methods: {
                confirmCall() {
                    this.$emitter.emit('open-confirm-modal', {
                        title: "@lang('admin::app.integrations.zadarma.call.confirm-title')",
                        message: this.contactName
                            ? "@lang('admin::app.integrations.zadarma.call.confirm-message')" + ' ' + this.contactName + ' (' + this.phoneNumber + ')?'
                            : "@lang('admin::app.integrations.zadarma.call.confirm-message-phone')" + ' ' + this.phoneNumber + '?',
                        options: {
                            btnDisagree: "@lang('admin::app.components.modal.confirm.disagree-btn')",
                            btnAgree: "@lang('admin::app.components.modal.confirm.agree-btn')",
                        },
                        agree: () => this.placeCall(),
                    });
                },

                placeCall() {
                    this.isCalling = true;

                    this.$axios.post(this.endpoint, {
                        entity_type: this.entityType,
                        entity_id: this.entityId,
                        phone: this.phoneNumber,
                        contact_name: this.contactName,
                        participant_person_id: this.participantPersonId,
                    })
                        .then((response) => {
                            this.$emitter.emit('add-flash', {
                                type: 'success',
                                message: response.data.message,
                            });

                            if (response.data.data) {
                                this.$emitter.emit('on-activity-added', response.data.data);
                            }
                        })
                        .catch((error) => {
                            this.$emitter.emit('add-flash', {
                                type: 'error',
                                message: error.response?.data?.message || "@lang('admin::app.integrations.zadarma.call.failed')",
                            });
                        })
                        .finally(() => {
                            this.isCalling = false;
                        });
                },
            },
        });
    </script>
@endPushOnce
