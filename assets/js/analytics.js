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
        platformData.forEach(function(item) {
            if (!platforms[item.platform]) {
                platforms[item.platform] = { success: 0, error: 0 };
            }
            if (item.status === 'success') {
                platforms[item.platform].success = parseInt(item.count);
            } else if (item.status === 'error') {
                platforms[item.platform].error = parseInt(item.count);
            }
        });

        const labels = Object.keys(platforms).map(p => p.charAt(0).toUpperCase() + p.slice(1));
        const successData = Object.values(platforms).map(p => p.success);
        const errorData = Object.values(platforms).map(p => p.error);

        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Successful',
                        data: successData,
                        backgroundColor: '#46b450',
                        borderColor: '#46b450',
                        borderWidth: 1
                    },
                    {
                        label: 'Failed',
                        data: errorData,
                        backgroundColor: '#dc3232',
                        borderColor: '#dc3232',
                        borderWidth: 1
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
                            font: {
                                size: 13
                            },
                            padding: 15
                        }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        padding: 12,
                        titleFont: {
                            size: 14
                        },
                        bodyFont: {
                            size: 13
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1,
                            font: {
                                size: 12
                            }
                        },
                        grid: {
                            color: 'rgba(0, 0, 0, 0.05)'
                        }
                    },
                    x: {
                        ticks: {
                            font: {
                                size: 12
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
            container.html('<p style="text-align: center; color: #666;">No activity found for the selected date range.</p>');
            return;
        }

        activities.forEach(function(activity) {
            const statusClass = activity.status === 'success' ? 'success' : 'error';
            const statusLabel = activity.status === 'success' ? 'Success' : 'Failed';
            const platformName = activity.platform.charAt(0).toUpperCase() + activity.platform.slice(1);
            const date = new Date(activity.published_at);
            const formattedDate = date.toLocaleString();

            const activityHtml = `
                <div class="activity-item">
                    <div class="activity-info">
                        <strong>Post #${activity.post_id} → ${platformName}</strong>
                        <div class="activity-meta">${formattedDate}</div>
                    </div>
                    <span class="activity-status ${statusClass}">${statusLabel}</span>
                </div>
            `;
            container.append(activityHtml);
        });
    }
});
