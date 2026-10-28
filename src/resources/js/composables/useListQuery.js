import { ref, computed, onUnmounted, getCurrentInstance } from 'vue';
import { router, usePage } from '@inertiajs/vue3';

export function useListQuery(baseUrl = '', options = {}) {
    const page = usePage();
    const defaults = {
        search: '',
        sort: '',
        direction: 'asc',
        perPage: 15,
        filters: {},
        ...options.defaults,
    };

    function getInitialParams() {
        if (typeof window === 'undefined') return { ...defaults };
        const searchParams = new URLSearchParams(window.location.search);
        const initialFilters = { ...defaults.filters };

        for (const [key, value] of searchParams.entries()) {
            if (key.startsWith('filter[') && key.endsWith(']')) {
                const filterKey = key.slice(7, -1);
                initialFilters[filterKey] = value;
            }
        }

        return {
            search: searchParams.get('search') ?? defaults.search,
            sort: searchParams.get('sort') ?? defaults.sort,
            direction: searchParams.get('direction') ?? defaults.direction,
            perPage: Number(searchParams.get('per_page')) || defaults.perPage,
            page: Number(searchParams.get('page')) || 1,
            filters: initialFilters,
        };
    }

    const initial = getInitialParams();

    const search = ref(initial.search);
    const sortKey = ref(initial.sort);
    const sortDirection = ref(initial.direction);
    const perPage = ref(initial.perPage);
    const currentPage = ref(initial.page);
    const filters = ref({ ...initial.filters });
    const isLoading = ref(false);

    let debounceTimer = null;
    let lastQueryString = '';

    const query = computed(() => {
        const q = {};
        if (search.value) q.search = search.value;
        if (sortKey.value) {
            q.sort = sortKey.value;
            q.direction = sortDirection.value;
        }
        if (perPage.value && perPage.value !== defaults.perPage) {
            q.per_page = perPage.value;
        }
        if (currentPage.value > 1) {
            q.page = currentPage.value;
        }
        for (const [k, v] of Object.entries(filters.value)) {
            if (v !== '' && v !== null && v !== undefined) {
                q[`filter[${k}]`] = v;
            }
        }
        return q;
    });

    function navigate(params) {
        const targetUrl = baseUrl || (page.url ? page.url.split('?')[0] : '/');
        const queryString = new URLSearchParams(params).toString();

        // Avoid sending duplicate request if parameters haven't changed
        if (queryString === lastQueryString) {
            return;
        }
        lastQueryString = queryString;

        isLoading.value = true;
        router.get(targetUrl, params, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onFinish: () => {
                isLoading.value = false;
            },
        });
    }

    function setSearch(value, immediate = false) {
        search.value = value;
        currentPage.value = 1;

        if (debounceTimer) clearTimeout(debounceTimer);

        if (immediate) {
            navigate(query.value);
        } else {
            debounceTimer = setTimeout(() => {
                navigate(query.value);
            }, 300);
        }
    }

    function setPage(pageNum) {
        if (pageNum === currentPage.value) return;
        currentPage.value = pageNum;
        navigate(query.value);
    }

    function setPerPage(count) {
        if (count === perPage.value) return;
        perPage.value = count;
        currentPage.value = 1;
        navigate(query.value);
    }

    function setSort(key, direction = null) {
        if (sortKey.value === key) {
            sortDirection.value = direction || (sortDirection.value === 'asc' ? 'desc' : 'asc');
        } else {
            sortKey.value = key;
            sortDirection.value = direction || 'asc';
        }
        currentPage.value = 1;
        navigate(query.value);
    }

    function setFilter(key, value) {
        filters.value = {
            ...filters.value,
            [key]: value,
        };
        currentPage.value = 1;
        navigate(query.value);
    }

    function resetFilters() {
        search.value = '';
        filters.value = {};
        currentPage.value = 1;
        navigate(query.value);
    }

    if (getCurrentInstance()) {
        onUnmounted(() => {
            if (debounceTimer) clearTimeout(debounceTimer);
        });
    }

    return {
        search,
        sortKey,
        sortDirection,
        perPage,
        currentPage,
        filters,
        isLoading,
        query,
        setSearch,
        setPage,
        setPerPage,
        setSort,
        setFilter,
        resetFilters,
        navigate,
    };
}
