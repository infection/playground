import '../css/app.css';
import '../css/explore.css';

// choosing a mutator filters by it, "All mutators" leaves the escaped parameter out of the URL
document.querySelectorAll('select[data-autosubmit]').forEach((select) => {
    select.addEventListener('change', () => {
        select.disabled = select.value === '';
        select.form.submit();
    });
});
