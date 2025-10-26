function initTopProductsChart(data) {
    const ctx = document.getElementById('topProductsChart').getContext('2d');
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: data.map(item => item.label),
            datasets: [{
                label: 'تعداد فروش',
                data: data.map(item => item.value),
                backgroundColor: 'rgba(37, 99, 235, 0.6)',
                borderColor: 'rgba(37, 99, 235, 1)',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            scales: {
                y: {
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: 'تعداد فروش'
                    }
                },
                x: {
                    title: {
                        display: true,
                        text: 'محصولات'
                    }
                }
            }
        }
    });
}

document.querySelectorAll('.export-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        const type = btn.dataset.type;
        alert(`در حال تولید خروجی ${type}... (این یک شبیه‌سازی است)`);
        // Here you would implement actual export logic using a library like jsPDF or XLSX
    });
});