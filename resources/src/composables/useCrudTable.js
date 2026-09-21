import { ref, computed, createVNode } from 'vue';
import { message, Modal } from 'ant-design-vue';
import { ExclamationCircleOutlined } from '@ant-design/icons-vue';
import http from '../lib/http';
import { t } from '../i18n';

/**
 * The server-side list pattern every legacy CRUD page repeats, in one place.
 *
 * Legacy list endpoints all take the same query params (as vue-good-table sent):
 *   page, SortField, SortType, search, limit
 * and answer { <resource>: [...], totalRows: n }.
 *
 *   const crud = useCrudTable('brands', { rowsKey: 'brands' });
 *   crud.fetchRows();
 *
 * rowsKey is optional: without it the first array in the payload is used.
 *
 * `endpoint` may also be a function returning the path, for pages that switch
 * between sibling endpoints (e.g. the fleet report tabs) and want ONE instance
 * so the filters, page size and search survive the switch.
 */
/**
 * "All" page size: a limit far above any real dataset, so one page holds
 * everything. The legacy endpoints plug `limit` straight into the query.
 */
export const ALL_PAGE_SIZE = 100000;
export const PAGE_SIZE_OPTIONS = ['10', '25', '50', '100', String(ALL_PAGE_SIZE)];
/** Labels the ALL_PAGE_SIZE sentinel "All" in the size-changer select. */
export const buildPageSizeOptionText = ({ value }) =>
    (Number(value) >= ALL_PAGE_SIZE ? t('All', 'All') : `${value}`);

/**
 * A 422 is the server deliberately explaining why an action is not allowed
 * (e.g. a unit still has sub-units), so show that reason. Any other failure is
 * infrastructure noise the user can't act on — keep the friendly generic text.
 */
function actionError(e, fallbackKey, fallbackText) {
    if (e?.status === 422 && e?.data?.message) return e.data.message;
    return t(fallbackKey, fallbackText);
}

export function useCrudTable(endpoint, options = {}) {
    const resolveEndpoint = () => (typeof endpoint === 'function' ? endpoint() : endpoint);

    const {
        rowsKey = null,
        limit: initialLimit = 10,
        sortField: initialSortField = 'id',
        sortType: initialSortType = 'desc',
        params = null,          // () => ({ extra: 'query params' })
        // For endpoints that nest their payload, e.g. report/top_suppliers
        // answers { data: { rows, totalRows, kpis }, warehouses }:
        //   select: p => ({ rows: p.data?.rows || [], total: p.data?.totalRows || 0 })
        select = null,
        onError = null,
        // The documents use flat routes (e.g. sales_delete_by_selection)
        // instead of the usual `${endpoint}/delete/by_selection`.
        bulkDeleteEndpoint = null,
    } = options;

    const rows = ref([]);
    const total = ref(0);
    const loading = ref(false);
    const page = ref(1);
    const limit = ref(initialLimit);
    const search = ref('');
    const sortField = ref(initialSortField);
    const sortType = ref(initialSortType);
    const selectedIds = ref([]);
    // Last raw response: several endpoints ship extra lists alongside the rows
    // (e.g. units returns Units_base for the base-unit select).
    const payload = ref(null);

    function pickRows(payload) {
        if (!payload || typeof payload !== 'object') return [];
        if (rowsKey && Array.isArray(payload[rowsKey])) return payload[rowsKey];
        const firstArray = Object.values(payload).find(v => Array.isArray(v));
        return firstArray || [];
    }

    async function fetchRows() {
        loading.value = true;
        try {
            const data = await http.get(resolveEndpoint(), {
                page: page.value,
                SortField: sortField.value,
                SortType: sortType.value,
                search: search.value,
                limit: limit.value,
                ...(params ? params() : {}),
            });
            payload.value = data;
            if (select) {
                const picked = select(data) || {};
                rows.value = Array.isArray(picked.rows) ? picked.rows : [];
                total.value = Number(picked.total) || rows.value.length;
            } else {
                rows.value = pickRows(data);
                total.value = Number(data?.totalRows) || rows.value.length;
            }
            selectedIds.value = [];
        } catch (e) {
            if (onError) onError(e);
            else message.error(t('InvalidData', 'Could not load data'));
        } finally {
            loading.value = false;
        }
    }

    /** Reset to page 1 and refetch — use after search or filter changes. */
    function reload() {
        page.value = 1;
        return fetchRows();
    }

    const pagination = computed(() => ({
        current: page.value,
        pageSize: limit.value,
        total: total.value,
        showSizeChanger: true,
        pageSizeOptions: PAGE_SIZE_OPTIONS,
        buildOptionText: buildPageSizeOptionText,
        showTotal: n => `${n}`,
    }));

    /** Wire to <a-table @change>. */
    function onChange(pag, _filters, sorter) {
        if (pag) {
            page.value = pag.current;
            limit.value = pag.pageSize;
        }
        // Only columns declaring `sorter: true` sort server-side. Function
        // sorters (auto-injected by DataTable) are handled by a-table on the
        // loaded rows — their fields usually aren't real DB columns, so they
        // must never reach the backend as SortField. `sorter.column` is also
        // unset when a sort is cleared; keeping the previous SortField then
        // matches the old behavior.
        if (sorter && sorter.field && sorter.column && typeof sorter.column.sorter !== 'function') {
            sortField.value = sorter.field;
            sortType.value = sorter.order === 'ascend' ? 'asc' : 'desc';
        }
        return fetchRows();
    }

    /**
     * Delete with the standard confirmation. `label` names the record in the
     * dialog; `deleteEndpoint` overrides the default `${endpoint}/${id}`.
     */
    function remove(record, { label = null, deleteEndpoint = null } = {}) {
        const name = label || record?.name || '';
        Modal.confirm({
            title: t('Delete_Title', 'Are you sure?'),
            icon: createVNode(ExclamationCircleOutlined),
            content: name
                ? `${t('Delete_Text', "You won't be able to revert this!")} — ${name}`
                : t('Delete_Text', "You won't be able to revert this!"),
            okText: t('Delete_confirmButtonText', 'Yes, delete it!'),
            okType: 'danger',
            cancelText: t('Delete_cancelButtonText', 'Cancel'),
            async onOk() {
                try {
                    await http.delete(deleteEndpoint || `${resolveEndpoint()}/${record.id}`);
                    message.success(t('Deleted_in_successfully', 'Deleted successfully'));
                    // Step back a page when the last row of the page is removed.
                    if (rows.value.length === 1 && page.value > 1) page.value--;
                    await fetchRows();
                } catch (e) {
                    message.error(actionError(e, 'InvalidData', 'Could not delete this record'));
                }
            },
        });
    }

    /** Bind to <a-table :row-selection> when a page supports bulk delete. */
    const rowSelection = computed(() => ({
        selectedRowKeys: selectedIds.value,
        onChange: keys => { selectedIds.value = keys; },
    }));

    /**
     * Bulk delete — the legacy contract is POST {endpoint}/delete/by_selection
     * with { selectedIds }.
     */
    function removeSelected() {
        if (!selectedIds.value.length) return;
        Modal.confirm({
            title: t('Delete_Title', 'Are you sure?'),
            icon: createVNode(ExclamationCircleOutlined),
            content: `${t('Delete_Text', "You won't be able to revert this!")} (${selectedIds.value.length})`,
            okText: t('Delete_confirmButtonText', 'Yes, delete it!'),
            okType: 'danger',
            cancelText: t('Delete_cancelButtonText', 'Cancel'),
            async onOk() {
                try {
                    await http.post(bulkDeleteEndpoint || `${resolveEndpoint()}/delete/by_selection`, { selectedIds: selectedIds.value });
                    message.success(t('Deleted_in_successfully', 'Deleted successfully'));
                    selectedIds.value = [];
                    await fetchRows();
                } catch (e) {
                    message.error(actionError(e, 'Delete_Therewassomethingwronge', 'Could not delete the selection'));
                }
            },
        });
    }

    /** Some edit forms need fields the list doesn't return (e.g. category code). */
    function getRecord(id) {
        return http.get(`${resolveEndpoint()}/${id}`);
    }

    return {
        rows, total, loading, page, limit, search, sortField, sortType, payload,
        selectedIds, rowSelection, pagination,
        fetchRows, reload, onChange, remove, removeSelected, getRecord,
    };
}
