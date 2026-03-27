/**
 * HashPoster Auto Posting JavaScript
 * Handles the auto posting rules interface
 */

class HashPosterAutoPosting {
    constructor() {
        this.init();
        this.bindEvents();
    }

    init() {
        this.rulesContainer = document.getElementById('hashposter-auto-posting-rules');
        this.addRuleBtn = document.getElementById('hashposter-add-rule');
        this.ruleTemplate = document.getElementById('hashposter-rule-template');

        if (this.addRuleBtn) {
            this.addRuleBtn.addEventListener('click', () => this.addNewRule());
        }

        this.loadExistingRules();
    }

    bindEvents() {
        // Delegate events for dynamically created elements
        document.addEventListener('click', (e) => {
            if (e.target.matches('.hashposter-delete-rule')) {
                e.preventDefault();
                this.deleteRule(e.target);
            }
        });

        document.addEventListener('submit', (e) => {
            if (e.target.matches('.hashposter-rule-form')) {
                e.preventDefault();
                this.saveRule(e.target);
            }
        });

        document.addEventListener('change', (e) => {
            if (e.target.matches('.hashposter-rule-trigger')) {
                this.toggleRuleFields(e.target);
            }
        });
    }

    addNewRule() {
        if (!this.ruleTemplate) return;

        const template = this.ruleTemplate.content.cloneNode(true);
        const ruleElement = template.querySelector('.hashposter-rule-item');

        if (ruleElement) {
            // Generate unique ID for the rule
            const ruleId = 'rule_' + Date.now();
            ruleElement.setAttribute('data-rule-id', ruleId);

            // Initialize Select2 for the new rule
            setTimeout(() => {
                this.initializeSelect2(ruleElement);
            }, 100);

            this.rulesContainer.appendChild(ruleElement);
        }
    }

    initializeSelect2(container) {
        const categorySelects = container.querySelectorAll('.hashposter-categories-select');
        const tagSelects = container.querySelectorAll('.hashposter-tags-select');

        categorySelects.forEach(select => {
            jQuery(select).select2({
                placeholder: hashposterAutoPosting.select2_placeholder,
                allowClear: true,
                width: '100%'
            });
        });

        tagSelects.forEach(select => {
            jQuery(select).select2({
                placeholder: hashposterAutoPosting.select2_placeholder,
                allowClear: true,
                width: '100%'
            });
        });
    }

    toggleRuleFields(triggerSelect) {
        const ruleItem = triggerSelect.closest('.hashposter-rule-item');
        const conditionFields = ruleItem.querySelector('.hashposter-condition-fields');
        const platformFields = ruleItem.querySelector('.hashposter-platform-fields');

        if (triggerSelect.value === 'category' || triggerSelect.value === 'tag') {
            conditionFields.style.display = 'block';
            platformFields.style.display = 'block';
        } else {
            conditionFields.style.display = 'none';
            platformFields.style.display = 'none';
        }
    }

    async saveRule(form) {
        const ruleItem = form.closest('.hashposter-rule-item');
        const ruleId = ruleItem.getAttribute('data-rule-id');
        const submitBtn = form.querySelector('button[type="submit"]');

        // Get form data
        const formData = new FormData(form);
        formData.append('action', 'hashposter_save_auto_posting_rule');
        formData.append('nonce', hashposterAutoPosting.nonce);
        formData.append('rule_id', ruleId);

        // Show loading state
        const originalText = submitBtn.textContent;
        submitBtn.textContent = hashposterAutoPosting.loading;
        submitBtn.disabled = true;

        try {
            const response = await fetch(hashposterAutoPosting.ajax_url, {
                method: 'POST',
                body: formData
            });

            const result = await response.json();

            if (result.success) {
                this.showNotice(hashposterAutoPosting.rule_saved, 'success');
                // Update rule ID if it's a new rule
                if (result.data && result.data.rule_id) {
                    ruleItem.setAttribute('data-rule-id', result.data.rule_id);
                }
            } else {
                this.showNotice(result.data?.message || hashposterAutoPosting.error, 'error');
            }
        } catch (error) {
            console.error('Auto posting save error:', error);
            this.showNotice(hashposterAutoPosting.error, 'error');
        } finally {
            submitBtn.textContent = originalText;
            submitBtn.disabled = false;
        }
    }

    async deleteRule(button) {
        if (!confirm(hashposterAutoPosting.confirm_delete)) {
            return;
        }

        const ruleItem = button.closest('.hashposter-rule-item');
        const ruleId = ruleItem.getAttribute('data-rule-id');

        const formData = new FormData();
        formData.append('action', 'hashposter_delete_auto_posting_rule');
        formData.append('nonce', hashposterAutoPosting.nonce);
        formData.append('rule_id', ruleId);

        button.textContent = hashposterAutoPosting.loading;
        button.disabled = true;

        try {
            const response = await fetch(hashposterAutoPosting.ajax_url, {
                method: 'POST',
                body: formData
            });

            const result = await response.json();

            if (result.success) {
                ruleItem.remove();
                this.showNotice(hashposterAutoPosting.rule_deleted, 'success');
            } else {
                this.showNotice(result.data?.message || hashposterAutoPosting.error, 'error');
                button.textContent = 'Delete';
                button.disabled = false;
            }
        } catch (error) {
            console.error('Auto posting delete error:', error);
            this.showNotice(hashposterAutoPosting.error, 'error');
            button.textContent = 'Delete';
            button.disabled = false;
        }
    }

    async loadExistingRules() {
        if (!this.rulesContainer) return;

        try {
            const response = await fetch(hashposterAutoPosting.ajax_url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'hashposter_get_auto_posting_rules',
                    nonce: hashposterAutoPosting.nonce
                })
            });

            const result = await response.json();

            if (result.success && result.data && result.data.rules) {
                this.renderExistingRules(result.data.rules);
            }
        } catch (error) {
            console.error('Load rules error:', error);
        }
    }

    renderExistingRules(rules) {
        Object.entries(rules).forEach(([ruleId, rule]) => {
            const ruleElement = this.createRuleElement(ruleId, rule);
            if (ruleElement) {
                this.rulesContainer.appendChild(ruleElement);
            }
        });
    }

    createRuleElement(ruleId, rule) {
        if (!this.ruleTemplate) return null;

        const template = this.ruleTemplate.content.cloneNode(true);
        const ruleElement = template.querySelector('.hashposter-rule-item');

        if (ruleElement) {
            ruleElement.setAttribute('data-rule-id', ruleId);

            // Populate form fields
            const nameField = ruleElement.querySelector('[name="rule_name"]');
            const triggerField = ruleElement.querySelector('[name="rule_trigger"]');
            const platformsField = ruleElement.querySelector('[name="platforms[]"]');
            const contentField = ruleElement.querySelector('[name="custom_content"]');

            if (nameField) nameField.value = rule.name || '';
            if (triggerField) triggerField.value = rule.trigger || '';
            if (contentField) contentField.value = rule.custom_content || '';

            // Set platforms
            if (platformsField && rule.platforms) {
                Array.from(platformsField.options).forEach(option => {
                    if (rule.platforms.includes(option.value)) {
                        option.selected = true;
                    }
                });
            }

            // Set categories/tags if applicable
            if (rule.trigger === 'category' && rule.categories) {
                const categorySelect = ruleElement.querySelector('.hashposter-categories-select');
                if (categorySelect) {
                    rule.categories.forEach(catId => {
                        const option = categorySelect.querySelector(`option[value="${catId}"]`);
                        if (option) option.selected = true;
                    });
                }
            }

            if (rule.trigger === 'tag' && rule.tags) {
                const tagSelect = ruleElement.querySelector('.hashposter-tags-select');
                if (tagSelect) {
                    rule.tags.forEach(tagId => {
                        const option = tagSelect.querySelector(`option[value="${tagId}"]`);
                        if (option) option.selected = true;
                    });
                }
            }

            // Initialize Select2
            setTimeout(() => {
                this.initializeSelect2(ruleElement);
                this.toggleRuleFields(triggerField);
            }, 100);
        }

        return ruleElement;
    }

    showNotice(message, type = 'info') {
        // Remove existing notices
        const existingNotices = document.querySelectorAll('.hashposter-notice');
        existingNotices.forEach(notice => notice.remove());

        // Create new notice
        const notice = document.createElement('div');
        notice.className = `notice notice-${type} is-dismissible hashposter-notice`;
        notice.innerHTML = `
            <p>${message}</p>
            <button type="button" class="notice-dismiss">
                <span class="screen-reader-text">Dismiss this notice.</span>
            </button>
        `;

        // Add to page
        const container = document.querySelector('.wrap') || document.body;
        container.insertBefore(notice, container.firstChild);

        // Auto-dismiss after 5 seconds
        setTimeout(() => {
            if (notice.parentNode) {
                notice.remove();
            }
        }, 5000);

        // Handle dismiss button
        const dismissBtn = notice.querySelector('.notice-dismiss');
        if (dismissBtn) {
            dismissBtn.addEventListener('click', () => notice.remove());
        }
    }
}

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    if (document.getElementById('hashposter-auto-posting-rules')) {
        new HashPosterAutoPosting();
    }
});