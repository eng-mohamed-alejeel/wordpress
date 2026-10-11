'use strict';
document.addEventListener('change', function (event) {
    const target = event.target;
    if (target.matches('input[name="_car_plate_types[]"]') && target.checked) {
        document.querySelectorAll('input[name="_car_plate_types[]"]').forEach(function (input) {
            if (input !== target) input.checked = false;
        });
    }
});
