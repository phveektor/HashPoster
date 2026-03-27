/**
 * HashPoster - Premium Admin JS
 */
(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function() {
        
        // 1. Sidebar Tab Navigation with Persistence
        const navItems = document.querySelectorAll('.hp-nav-item');
        const tabPanels = document.querySelectorAll('.hp-tab-panel');
        const activeTabInput = document.getElementById('hp_active_tab_input');
        const titleHeading = document.getElementById('hp_active_tab_title');
        const titleDesc = document.getElementById('hp_active_tab_desc');

        if (navItems.length > 0) {
            // Restore active tab from SessionStorage
            const storedTab = sessionStorage.getItem('hp_active_tab');
            if (storedTab) {
                const targetBtn = document.querySelector(`.hp-nav-item[data-tab="${storedTab}"]`);
                if (targetBtn) {
                    activateTab(targetBtn, storedTab);
                }
            }

            navItems.forEach(item => {
                item.addEventListener('click', function(e) {
                    e.preventDefault();
                    const tabId = this.getAttribute('data-tab');
                    activateTab(this, tabId);
                });
            });
        }

        function activateTab(navBtn, tabId) {
            // Update Active Nav
            navItems.forEach(btn => btn.classList.remove('active'));
            navBtn.classList.add('active');

            // Update Active Panel
            tabPanels.forEach(panel => panel.classList.remove('active'));
            const targetPanel = document.getElementById(tabId);
            if (targetPanel) {
                targetPanel.classList.add('active');
            }

            // Update Top Bar Typography
            if (titleHeading && titleDesc) {
                titleHeading.textContent = navBtn.getAttribute('data-title') || navBtn.textContent.trim();
                titleDesc.textContent = navBtn.getAttribute('data-desc') || '';
            }

            // Save state
            if (activeTabInput) {
                activeTabInput.value = tabId;
            }
            sessionStorage.setItem('hp_active_tab', tabId);
        }

        // 2. Clear status messages after 5 seconds automatically
        const notices = document.querySelectorAll('.hp-notice, .notice-success');
        notices.forEach(notice => {
            setTimeout(() => {
                notice.style.transition = 'opacity 0.5s ease';
                notice.style.opacity = '0';
                setTimeout(() => notice.remove(), 500);
            }, 4000);
        });

        // 3. Post Card Template Live Preview
        const templateInput = document.getElementById('hp_template_input');
        const previewBox = document.getElementById('hp_template_preview');
        const tokenBtns = document.querySelectorAll('.hp-token-btn');

        if (templateInput && previewBox) {
            // Live update the preview on typing
            templateInput.addEventListener('input', updatePreview);
            // Run exactly once on load to show current state
            updatePreview();

            // Insert tokens on click
            tokenBtns.forEach(btn => {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    const token = this.getAttribute('data-token');
                    insertAtCursor(templateInput, token);
                    updatePreview();
                });
            });
        }

        function updatePreview() {
            let text = templateInput.value;
            // Mock replacements
            text = text.replace(/{title}/g, '📰 My Premium Post Title');
            text = text.replace(/{excerpt}/g, 'This is a beautifully written excerpt summarizing the main points of the article...');
            text = text.replace(/{url}/g, 'https://example.com/premium-post');
            text = text.replace(/{short_url}/g, 'https://bit.ly/xyz123');
            text = text.replace(/{author}/g, 'Jane Doe');
            text = text.replace(/{date}/g, new Date().toLocaleDateString());
            text = text.replace(/{tags}/g, '#premium #design');
            text = text.replace(/{site_name}/g, 'My Awesome Site');

            // Preserve whitespace and newlines for realism
            previewBox.innerHTML = escapeHtml(text).replace(/\n/g, '<br>');
        }

        function insertAtCursor(myField, myValue) {
            if (myField.selectionStart || myField.selectionStart == '0') {
                var startPos = myField.selectionStart;
                var endPos = myField.selectionEnd;
                myField.value = myField.value.substring(0, startPos)
                    + myValue
                    + myField.value.substring(endPos, myField.value.length);
                myField.selectionStart = startPos + myValue.length;
                myField.selectionEnd = startPos + myValue.length;
            } else {
                myField.value += myValue;
            }
            myField.focus();
        }

        function escapeHtml(unsafe) {
            return unsafe
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#039;");
        }

        // 4. OAuth Handling with fetch()
        const oauthBtns = document.querySelectorAll('.hp-oauth-btn');
        oauthBtns.forEach(btn => {
            btn.addEventListener('click', async function(e) {
                e.preventDefault();
                const platform = this.getAttribute('data-platform');
                const originalText = this.innerHTML;
                
                this.innerHTML = '<span class="dashicons dashicons-update" style="animation: spin 2s linear infinite;"></span> Connecting...';
                this.disabled = true;

                try {
                    const formData = new URLSearchParams();
                    formData.append('action', 'hashposter_oauth_initiate');
                    formData.append('platform', platform);
                    // Use the oauth nonce injected by wp_localize_script
                    formData.append('nonce', typeof hashposterAdmin !== 'undefined' && hashposterAdmin.oauth_nonce ? hashposterAdmin.oauth_nonce : '');

                    const response = await fetch(
                        typeof hashposterAdmin !== 'undefined' ? hashposterAdmin.ajax_url : '/wp-admin/admin-ajax.php',
                        {
                            method: 'POST',
                            body: formData,
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded'
                            }
                        }
                    );
                    
                    const result = await response.json();
                    
                    if (result.success && result.data && result.data.auth_url) {
                        window.location.href = result.data.auth_url;
                    } else {
                        alert('Connection error: ' + (result.data || 'Unknown error. Please check your API keys inside the general settings first.'));
                        this.innerHTML = originalText;
                        this.disabled = false;
                    }
                } catch (error) {
                    alert('Network error occurred while trying to connect.');
                    this.innerHTML = originalText;
                    this.disabled = false;
                    console.error('HashPoster OAuth Error:', error);
                }
            });
        });

    });
})();