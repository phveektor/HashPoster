jQuery(document).ready(function($) {
    'use strict';

    // Load analytics data on page load
    loadAnalyticsData();

    function loadAnalyticsData() {
        $.ajax({
            url: hashposterAnalytics.ajax_url,
            type: 'POST',
            data: {
                action: 'hashposter_get_analytics_data',
                nonce: hashposterAnalytics.nonce,
                date_from: hashposterAnalytics.date_from,
                date_to: hashposterAnalytics.date_to
            },
            success: function(response) {
                if (response.success && response.data) {
                    updateStatCards(response.data);
                    renderPlatformChart(response.data.platform_data);
                    renderRecentActivity(response.data.recent_activity);
                }
            },
            error: function() {
                console.error('Failed to load analytics data');
                $('#activity-list').html('<div class="hp-empty-activity"><p>Unable to load activity data. Please refresh.</p></div>');
            }
        });
    }

    function updateStatCards(data) {
        $('#total-posts').text(data.total_posts);
        $('#successful-posts').text(data.successful_posts);
        $('#failed-posts').text(data.failed_posts);
        $('#success-rate').text(data.success_rate + '%');
    }

    function renderPlatformChart(platformData) {
        const ctx = document.getElementById('platform-chart');
        if (!ctx) return;

        // Process platform data
        const platforms = {};
        if (Array.isArray(platformData)) {
            platformData.forEach(function(item) {
                if (!platforms[item.platform]) {
                    platforms[item.platform] = { success: 0, error: 0 };
                }
                if (item.status === 'success') {
                    platforms[item.platform].success = parseInt(item.count, 10);
                } else if (item.status === 'error') {
                    platforms[item.platform].error = parseInt(item.count, 10);
                }
            });
        }

        const labels = Object.keys(platforms).map(function(p) {
            return p.charAt(0).toUpperCase() + p.slice(1);
        });
        const successData = Object.values(platforms).map(function(p) { return p.success; });
        const errorData = Object.values(platforms).map(function(p) { return p.error; });

        // Destroy existing chart instance if exists
        const existingChart = Chart.getChart(ctx);
        if (existingChart) {
            existingChart.destroy();
        }

        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Successful',
                        data: successData,
                        backgroundColor: '#10b981',
                        borderRadius: 6,
                        borderSkipped: false
                    },
                    {
                        label: 'Failed',
                        data: errorData,
                        backgroundColor: '#ef4444',
                        borderRadius: 6,
                        borderSkipped: false
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                        labels: {
                            color: '#475569',
                            font: {
                                size: 12,
                                weight: '600'
                            },
                            boxWidth: 12,
                            boxHeight: 12,
                            borderRadius: 3,
                            usePointStyle: false,
                            padding: 14
                        }
                    },
                    tooltip: {
                        backgroundColor: '#0f172a',
                        titleColor: '#f8fafc',
                        bodyColor: '#cbd5e1',
                        padding: 10,
                        cornerRadius: 8,
                        titleFont: {
                            size: 13,
                            weight: '600'
                        },
                        bodyFont: {
                            size: 12
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1,
                            color: '#64748b',
                            font: {
                                size: 11
                            }
                        },
                        grid: {
                            color: '#f1f5f9'
                        },
                        border: {
                            dash: [4, 4]
                        }
                    },
                    x: {
                        ticks: {
                            color: '#475569',
                            font: {
                                size: 12,
                                weight: '500'
                            }
                        },
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });
    }

    function renderRecentActivity(activities) {
        const container = $('#activity-list');
        container.empty();

        if (!activities || activities.length === 0) {
            container.html('<div class="hp-empty-activity"><span class="dashicons dashicons-info-outline"></span><p>No social post activity found for the selected date range.</p></div>');
            return;
        }

        activities.forEach(function(activity) {
            const isSuccess = (activity.status === 'success');
            const statusClass = isSuccess ? 'success' : 'error';
            const statusLabel = isSuccess ? 'Success' : 'Failed';
            const platformKey = (activity.platform || '').toLowerCase();
            const platformName = activity.platform ? (activity.platform.charAt(0).toUpperCase() + activity.platform.slice(1)) : 'Platform';
            
            let formattedDate = activity.published_at || '';
            if (activity.published_at) {
                const dateObj = new Date(activity.published_at);
                if (!isNaN(dateObj.getTime())) {
                    formattedDate = dateObj.toLocaleString([], {
                        month: 'short',
                        day: 'numeric',
                        hour: '2-digit',
                        minute: '2-digit'
                    });
                }
            }

            const editUrl = activity.edit_url || ('post.php?post=' + activity.post_id + '&action=edit');
            const postTitle = activity.post_title || ('Post #' + activity.post_id);
            const safeTitle = $('<div>').text(postTitle).html();

            const activityHtml = `
                <div class="hp-activity-row">
                    <div class="hp-activity-main">
                        <a href="${editUrl}" class="hp-activity-title" target="_blank" title="Edit: ${safeTitle}">
                            ${safeTitle}
                        </a>
                        <div class="hp-activity-sub">
                            <span class="hp-platform-pill hp-pill-${platformKey}">${platformName}</span>
                            <span class="hp-activity-time">${formattedDate}</span>
                            <span class="hp-activity-id">ID: ${activity.post_id}</span>
                        </div>
                    </div>
                    <div class="hp-activity-status-col">
                        <span class="hp-badge hp-badge-${statusClass}">${statusLabel}</span>
                    </div>
                </div>
            `;
            container.append(activityHtml);
        });
    }
});
