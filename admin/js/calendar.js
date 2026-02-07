(function() {
    'use strict';

    let calendar;

    document.addEventListener('DOMContentLoaded', function() {
        const calendarEl = document.getElementById('swps-calendar');
        if (!calendarEl) return;

        calendar = new FullCalendar.Calendar(calendarEl, {
            initialView: 'dayGridMonth',
            headerToolbar: {
                left: 'prev,next today',
                center: 'title',
                right: 'dayGridMonth,timeGridWeek,listWeek'
            },
            editable: true,
            selectable: true,
            eventStartEditable: true,
            height: 'auto',

            // Fetch events from AJAX.
            events: function(info, successCallback, failureCallback) {
                jQuery.ajax({
                    url: swpsCalendar.ajax_url,
                    type: 'GET',
                    data: {
                        action: 'swps_calendar_events',
                        nonce: swpsCalendar.nonce,
                        start: info.startStr,
                        end: info.endStr,
                    },
                    success: function(response) {
                        if (response.success) {
                            successCallback(response.data);
                        } else {
                            failureCallback();
                        }
                    },
                    error: failureCallback,
                });
            },

            // Click on a date to create a new topic.
            dateClick: function(info) {
                openCreateModal(info.dateStr);
            },

            // Click on an event to view/edit.
            eventClick: function(info) {
                const props = info.event.extendedProps;

                // Published posts open in WP editor.
                if (props.type === 'post' && info.event.url) {
                    return; // Let the default link behavior work.
                }

                // Topics open the detail modal.
                if (props.type === 'topic') {
                    info.jsEvent.preventDefault();
                    openDetailModal(info.event);
                }
            },

            // Drag and drop to reschedule.
            eventDrop: function(info) {
                const props = info.event.extendedProps;

                // Only allow rescheduling topics, not published posts.
                if (props.type !== 'topic') {
                    info.revert();
                    return;
                }

                jQuery.ajax({
                    url: swpsCalendar.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'swps_calendar_update_topic',
                        nonce: swpsCalendar.nonce,
                        topic_id: info.event.id,
                        date: info.event.startStr,
                    },
                    error: function() {
                        info.revert();
                        alert('Failed to reschedule topic.');
                    },
                });
            },
        });

        calendar.render();

        // Create topic form handler.
        const createForm = document.getElementById('swps-topic-create-form');
        if (createForm) {
            createForm.addEventListener('submit', function(e) {
                e.preventDefault();
                createTopic();
            });
        }

        // Modal close buttons.
        document.querySelectorAll('.swps-modal-close, .swps-modal-overlay').forEach(function(el) {
            el.addEventListener('click', function() {
                closeAllModals();
            });
        });

        // Delete topic button.
        const deleteBtn = document.getElementById('swps-topic-delete');
        if (deleteBtn) {
            deleteBtn.addEventListener('click', function() {
                const topicId = this.dataset.topicId;
                if (confirm('Delete this topic from the queue?')) {
                    deleteTopic(topicId);
                }
            });
        }
    });

    function openCreateModal(dateStr) {
        const modal = document.getElementById('swps-create-modal');
        if (!modal) return;

        document.getElementById('swps-topic-date').value = dateStr;
        document.getElementById('swps-topic-title').value = '';
        document.getElementById('swps-topic-notes').value = '';
        document.getElementById('swps-topic-template').value = 'auto';

        modal.classList.add('swps-modal-open');
    }

    function openDetailModal(event) {
        const modal = document.getElementById('swps-detail-modal');
        if (!modal) return;

        const props = event.extendedProps;

        document.getElementById('swps-detail-title').textContent = event.title;
        document.getElementById('swps-detail-date').textContent = event.startStr;
        document.getElementById('swps-detail-status').textContent = props.status;
        document.getElementById('swps-detail-template').textContent = props.template || 'auto';
        document.getElementById('swps-detail-notes').textContent = props.notes || 'No notes';

        const deleteBtn = document.getElementById('swps-topic-delete');
        if (deleteBtn) {
            deleteBtn.dataset.topicId = event.id;
            // Only show delete for queued/failed topics.
            deleteBtn.style.display = (props.status === 'queued' || props.status === 'failed') ? '' : 'none';
        }

        modal.classList.add('swps-modal-open');
    }

    function closeAllModals() {
        document.querySelectorAll('.swps-modal').forEach(function(modal) {
            modal.classList.remove('swps-modal-open');
        });
    }

    function createTopic() {
        const title    = document.getElementById('swps-topic-title').value.trim();
        const date     = document.getElementById('swps-topic-date').value;
        const template = document.getElementById('swps-topic-template').value;
        const notes    = document.getElementById('swps-topic-notes').value;

        if (!title) {
            alert('Please enter a topic title.');
            return;
        }

        const submitBtn = document.querySelector('#swps-topic-create-form button[type="submit"]');
        submitBtn.disabled = true;
        submitBtn.textContent = 'Adding...';

        jQuery.ajax({
            url: swpsCalendar.ajax_url,
            type: 'POST',
            data: {
                action: 'swps_calendar_create_topic',
                nonce: swpsCalendar.nonce,
                title: title,
                date: date + ' 09:00:00',
                template: template,
                notes: notes,
            },
            success: function(response) {
                if (response.success) {
                    closeAllModals();
                    calendar.refetchEvents();
                } else {
                    alert(response.data.message || 'Failed to create topic.');
                }
            },
            error: function() {
                alert('Failed to create topic.');
            },
            complete: function() {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Add to Queue';
            },
        });
    }

    function deleteTopic(topicId) {
        jQuery.ajax({
            url: swpsCalendar.ajax_url,
            type: 'POST',
            data: {
                action: 'swps_calendar_delete_topic',
                nonce: swpsCalendar.nonce,
                topic_id: topicId,
            },
            success: function(response) {
                if (response.success) {
                    closeAllModals();
                    calendar.refetchEvents();
                }
            },
        });
    }

})();
