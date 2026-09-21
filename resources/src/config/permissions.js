/**
 * Permission catalogue — every group/checkbox from the legacy
 * Create_permission.vue template (extracted, not hand-written), so the Vue 3
 * role editor offers exactly the same 239 permissions in the same order.
 * l = i18n key, f = fallback label used when the key is missing.
 */
export const PERMISSION_GROUPS = [
    {
        "label": "dashboard",
        "items": [
            {
                "v": "dashboard",
                "l": "dashboard"
            },
            {
                "v": "sales_3d_dashboard",
                "l": "sales_3d_dashboard"
            }
        ]
    },
    {
        "label": "UserManagement",
        "items": [
            {
                "v": "users_view",
                "l": "View"
            },
            {
                "v": "users_add",
                "l": "Add"
            },
            {
                "v": "users_edit",
                "l": "Edit"
            },
            {
                "v": "users_delete",
                "l": "Del"
            }
        ]
    },
    {
        "label": "UserPermissions",
        "items": [
            {
                "v": "permissions_view",
                "l": "View"
            },
            {
                "v": "permissions_add",
                "l": "Add"
            },
            {
                "v": "permissions_edit",
                "l": "Edit"
            },
            {
                "v": "permissions_delete",
                "l": "Del"
            }
        ]
    },
    {
        "label": "Products",
        "items": [
            {
                "v": "products_view",
                "l": "View"
            },
            {
                "v": "products_add",
                "l": "Add"
            },
            {
                "v": "products_edit",
                "l": "Edit"
            },
            {
                "v": "products_delete",
                "l": "Del"
            },
            {
                "v": "barcode_view",
                "l": "Barcode"
            },
            {
                "v": "product_import",
                "l": "import_products"
            },
            {
                "v": "category",
                "l": "Categories"
            },
            {
                "v": "subcategory",
                "l": "SubCategory"
            },
            {
                "v": "brand",
                "l": "Brand"
            },
            {
                "v": "unit",
                "l": "Units"
            },
            {
                "v": "count_stock",
                "l": "count_stock",
                "f": "Count Stock"
            },
            {
                "v": "subscription_product",
                "l": "subscription_product",
                "f": "Subscription Product"
            },
            {
                "v": "opening_stock_import",
                "l": "opening_stock_import",
                "f": "Opening Stock Import"
            },
            {
                "v": "serial_numbers",
                "l": "Serial_Numbers"
            },
            {
                "v": "size_guides",
                "l": "Size_Guides"
            },
            {
                "v": "batch_view",
                "l": "batch_view",
                "f": "View Batches"
            },
            {
                "v": "batch_manage",
                "l": "batch_manage",
                "f": "Manage Batches"
            },
            {
                "v": "batch_writeoff",
                "l": "batch_writeoff",
                "f": "Write-Off Batches"
            },
            {
                "v": "batch_force_override",
                "l": "batch_force_override",
                "f": "Override Expiry Block"
            },
            {
                "v": "expiry_report",
                "l": "expiry_report",
                "f": "Expiry Report"
            },
            {
                "v": "Batch_Register_Report",
                "l": "Batch_Register_Report",
                "f": "Batch Register Report"
            }
        ]
    },
    {
        "label": "StockAdjustement",
        "items": [
            {
                "v": "adjustment_view",
                "l": "View"
            },
            {
                "v": "adjustment_add",
                "l": "Add"
            },
            {
                "v": "adjustment_edit",
                "l": "Edit"
            },
            {
                "v": "adjustment_delete",
                "l": "Del"
            }
        ]
    },
    {
        "label": "Damages",
        "raw": true,
        "items": [
            {
                "v": "damage_view",
                "l": "damage_view",
                "f": "Damages"
            }
        ]
    },
    {
        "label": "StockTransfers",
        "items": [
            {
                "v": "transfer_view",
                "l": "View"
            },
            {
                "v": "transfer_add",
                "l": "Add"
            },
            {
                "v": "transfer_edit",
                "l": "Edit"
            },
            {
                "v": "transfer_delete",
                "l": "Del"
            }
        ]
    },
    {
        "label": "Accounting",
        "items": [
            {
                "v": "accounting_dashboard",
                "l": "accounting_dashboard",
                "f": "Accounting dashboard"
            },
            {
                "v": "chart_of_accounts",
                "l": "chart_of_accounts",
                "f": "Chart of accounts"
            },
            {
                "v": "journal_entries",
                "l": "journal_entries",
                "f": "journal entries"
            },
            {
                "v": "trial_balance",
                "l": "trial_balance",
                "f": "Trial balance"
            },
            {
                "v": "accounting_profit_loss",
                "l": "accounting_profit_loss",
                "f": "Accounting profit & loss"
            },
            {
                "v": "balance_sheet",
                "l": "balance_sheet",
                "f": "Balance sheet"
            },
            {
                "v": "accounting_tax_report",
                "l": "accounting_tax_report",
                "f": "Accounting tax report"
            },
            {
                "v": "account",
                "l": "List_accounts"
            },
            {
                "v": "transfer_money",
                "l": "transfer_money",
                "f": "Transfer money"
            },
            {
                "v": "expense_view",
                "l": "expense_view"
            },
            {
                "v": "expense_add",
                "l": "expense_add"
            },
            {
                "v": "expense_edit",
                "l": "expense_edit"
            },
            {
                "v": "expense_delete",
                "l": "expense_delete"
            },
            {
                "v": "deposit_view",
                "l": "deposit_view"
            },
            {
                "v": "deposit_add",
                "l": "deposit_add"
            },
            {
                "v": "deposit_edit",
                "l": "deposit_edit"
            },
            {
                "v": "deposit_delete",
                "l": "deposit_delete"
            }
        ]
    },
    {
        "label": "Asset Management",
        "raw": true,
        "items": [
            {
                "v": "assets",
                "l": "Assets"
            },
            {
                "v": "asset_assignments",
                "l": "Assignments",
                "f": "Assignments"
            },
            {
                "v": "asset_maintenance",
                "l": "Maintenance",
                "f": "Maintenance"
            },
            {
                "v": "asset_transfers",
                "l": "Transfers",
                "f": "Transfers"
            },
            {
                "v": "asset_reports",
                "l": "Reports",
                "f": "Asset reports"
            }
        ]
    },
    {
        "label": "Service_Maintenance",
        "items": [
            {
                "v": "service_jobs",
                "l": "Service_Maintenance"
            }
        ]
    },
    {
        "label": "Sales",
        "items": [
            {
                "v": "Sales_view",
                "l": "View"
            },
            {
                "v": "Sales_add",
                "l": "Add"
            },
            {
                "v": "Sales_edit",
                "l": "Edit"
            },
            {
                "v": "Sales_delete",
                "l": "Del"
            },
            {
                "v": "Pos_view",
                "l": "pointofsales"
            },
            {
                "v": "promotion",
                "l": "Promotions",
                "f": "Promotions"
            },
            {
                "v": "real_time_sales_counter",
                "l": "Real_time_Sales_Counter",
                "f": "Real-time Sales Counter"
            },
            {
                "v": "kitchen_display_view",
                "l": "KitchenDisplay",
                "f": "Kitchen Display"
            },
            {
                "v": "kitchen_display_manage",
                "l": "KitchenDisplay",
                "f": "Kitchen Display",
                "l2": "Manage",
                "f2": "Manage",
                "sep": " - "
            },
            {
                "v": "customer_display_screen_setup",
                "l": "customer_display_screen_setup",
                "f": "customer display screen setup"
            },
            {
                "v": "shipment",
                "l": "Shipments"
            },
            {
                "v": "edit_product_sale",
                "l": "Change_product_details"
            },
            {
                "v": "edit_tax_discount_shipping_sale",
                "l": "edit_tax_and_discount_and_shipping"
            }
        ]
    },
    {
        "label": "Commissions",
        "items": [
            {
                "v": "commissions_view",
                "l": "View"
            },
            {
                "v": "commissions_add",
                "l": "Add"
            },
            {
                "v": "commissions_edit",
                "l": "Edit"
            },
            {
                "v": "commissions_delete",
                "l": "Del"
            },
            {
                "v": "webhooks_view",
                "l": "View"
            },
            {
                "v": "webhooks_add",
                "l": "Add"
            },
            {
                "v": "webhooks_edit",
                "l": "Edit"
            },
            {
                "v": "webhooks_delete",
                "l": "Del"
            },
            {
                "v": "knowledge_base_view",
                "l": "View",
                "f": "View",
                "l2": "Manage",
                "f2": "Manage",
                "sep": " / "
            }
        ]
    },
    {
        "label": "Purchases",
        "items": [
            {
                "v": "Purchases_view",
                "l": "View"
            },
            {
                "v": "Purchases_add",
                "l": "Add"
            },
            {
                "v": "Purchases_edit",
                "l": "Edit"
            },
            {
                "v": "Purchases_delete",
                "l": "Del"
            },
            {
                "v": "edit_product_purchase",
                "l": "Change_product_details"
            },
            {
                "v": "edit_tax_discount_shipping_purchase",
                "l": "edit_tax_and_discount_and_shipping"
            }
        ]
    },
    {
        "label": "Quotations",
        "items": [
            {
                "v": "Quotations_view",
                "l": "View"
            },
            {
                "v": "Quotations_add",
                "l": "Add"
            },
            {
                "v": "Quotations_edit",
                "l": "Edit"
            },
            {
                "v": "Quotations_delete",
                "l": "Del"
            },
            {
                "v": "edit_product_quotation",
                "l": "Change_product_details"
            },
            {
                "v": "edit_tax_discount_shipping_quotation",
                "l": "edit_tax_and_discount_and_shipping"
            }
        ]
    },
    {
        "label": "SalesReturn",
        "items": [
            {
                "v": "Sale_Returns_view",
                "l": "View"
            },
            {
                "v": "Sale_Returns_add",
                "l": "Add"
            },
            {
                "v": "Sale_Returns_edit",
                "l": "Edit"
            },
            {
                "v": "Sale_Returns_delete",
                "l": "Del"
            }
        ]
    },
    {
        "label": "PurchasesReturn",
        "items": [
            {
                "v": "Purchase_Returns_view",
                "l": "View"
            },
            {
                "v": "Purchase_Returns_add",
                "l": "Add"
            },
            {
                "v": "Purchase_Returns_edit",
                "l": "Edit"
            },
            {
                "v": "Purchase_Returns_delete",
                "l": "Del"
            }
        ]
    },
    {
        "label": "PaymentsSales",
        "items": [
            {
                "v": "payment_sales_view",
                "l": "View"
            },
            {
                "v": "payment_sales_add",
                "l": "Add"
            },
            {
                "v": "payment_sales_edit",
                "l": "Edit"
            },
            {
                "v": "payment_sales_delete",
                "l": "Del"
            }
        ]
    },
    {
        "label": "PaymentsPurchases",
        "items": [
            {
                "v": "payment_purchases_view",
                "l": "View"
            },
            {
                "v": "payment_purchases_add",
                "l": "Add"
            },
            {
                "v": "payment_purchases_edit",
                "l": "Edit"
            },
            {
                "v": "payment_purchases_delete",
                "l": "Del"
            }
        ]
    },
    {
        "label": "PaymentsReturns",
        "items": [
            {
                "v": "payment_returns_view",
                "l": "View"
            },
            {
                "v": "payment_returns_add",
                "l": "Add"
            },
            {
                "v": "payment_returns_edit",
                "l": "Edit"
            },
            {
                "v": "payment_returns_delete",
                "l": "Del"
            }
        ]
    },
    {
        "label": "Customers",
        "items": [
            {
                "v": "Customers_view",
                "l": "View"
            },
            {
                "v": "Customers_add",
                "l": "Add"
            },
            {
                "v": "Customers_edit",
                "l": "Edit"
            },
            {
                "v": "Customers_delete",
                "l": "Del"
            },
            {
                "v": "customers_import",
                "l": "Import_Customers"
            },
            {
                "v": "pay_due",
                "l": "pay_all_sell_due_at_a_time"
            },
            {
                "v": "pay_sale_return_due",
                "l": "pay_all_sell_return_due_at_a_time"
            }
        ]
    },
    {
        "label": "Suppliers",
        "items": [
            {
                "v": "Suppliers_view",
                "l": "View"
            },
            {
                "v": "Suppliers_add",
                "l": "Add"
            },
            {
                "v": "Suppliers_edit",
                "l": "Edit"
            },
            {
                "v": "Suppliers_delete",
                "l": "Del"
            },
            {
                "v": "Suppliers_import",
                "l": "Import_Suppliers"
            },
            {
                "v": "pay_supplier_due",
                "l": "pay_all_purchase_due_at_a_time"
            },
            {
                "v": "pay_purchase_return_due",
                "l": "pay_all_purchase_return_due_at_a_time"
            }
        ]
    },
    {
        "label": "Reports",
        "items": [
            {
                "v": "Reports_payments_Sales",
                "l": "Reports_payments_Sales"
            },
            {
                "v": "Reports_payments_Purchases",
                "l": "Reports_payments_Purchases"
            },
            {
                "v": "Reports_payments_Sale_Returns",
                "l": "Reports_payments_Sale_Return"
            },
            {
                "v": "Reports_payments_purchase_Return",
                "l": "Reports_payments_Purchase_Return"
            },
            {
                "v": "inventory_valuation",
                "l": "inventory_valuation",
                "f": "Inventory Valuation Summary"
            },
            {
                "v": "report_transactions",
                "l": "report_transactions",
                "f": "Report Transactions"
            },
            {
                "v": "cash_flow_report",
                "l": "cash_flow_report",
                "f": "Cash flow report"
            },
            {
                "v": "report_attendance_summary",
                "l": "report_attendance_summary",
                "f": "Report attendance summary"
            },
            {
                "v": "seller_report",
                "l": "seller_report",
                "f": "Seller Report"
            },
            {
                "v": "report_sales_by_category",
                "l": "report_sales_by_category",
                "f": "Sales by Category"
            },
            {
                "v": "report_sales_by_brand",
                "l": "report_sales_by_brand",
                "f": "Sales by Brand"
            },
            {
                "v": "AI_Reports",
                "l": "AI_Reports",
                "f": "AI Reports"
            },
            {
                "v": "expenses_report",
                "l": "expenses_report",
                "f": "Expenses Report"
            },
            {
                "v": "deposits_report",
                "l": "deposits_report",
                "f": "Deposits Report"
            },
            {
                "v": "Reports_sales",
                "l": "SalesReport"
            },
            {
                "v": "Reports_purchase",
                "l": "PurchasesReport"
            },
            {
                "v": "Reports_customers",
                "l": "CustomersReport"
            },
            {
                "v": "inactive_customers_report",
                "l": "inactive_customers_report",
                "f": "Inactive customers report"
            },
            {
                "v": "service_jobs_report",
                "l": "Service_Jobs_Report"
            },
            {
                "v": "checklist_completion_report",
                "l": "Checklist_Completion_Report"
            },
            {
                "v": "customer_maintenance_history_report",
                "l": "Customer_Maintenance_History_Report"
            },
            {
                "v": "Reports_suppliers",
                "l": "SuppliersReport"
            },
            {
                "v": "Reports_profit",
                "l": "ProfitandLoss"
            },
            {
                "v": "Reports_quantity_alerts",
                "l": "ProductQuantityAlerts"
            },
            {
                "v": "Warehouse_report",
                "l": "WarehouseStockChart"
            },
            {
                "v": "internal_location_report",
                "l": "Internal_Location_Report"
            },
            {
                "v": "Top_products",
                "l": "Top_Selling_Products"
            },
            {
                "v": "Top_customers",
                "l": "Top_customers"
            },
            {
                "v": "users_report",
                "l": "Users_Report"
            },
            {
                "v": "stock_report",
                "l": "stock_report"
            },
            {
                "v": "product_report",
                "l": "product_report"
            },
            {
                "v": "cash_register_report",
                "l": "cash_register_report",
                "f": "Cash Register Report"
            },
            {
                "v": "report_warranty",
                "l": "report_warranty",
                "f": "Warranty / Guarantee Report"
            },
            {
                "v": "zeroSalesProducts",
                "l": "zeroSalesProducts",
                "f": "zero Sales Products Report"
            },
            {
                "v": "Dead_Stock_Report",
                "l": "Dead_Stock_Report",
                "f": "Dead Stock Report"
            },
            {
                "v": "Stock_Aging_Report",
                "l": "Stock_Aging_Report",
                "f": "Stock Aging Report"
            },
            {
                "v": "Stock_Transfer_Report",
                "l": "Stock_Transfer_Report",
                "f": "Stock Transfer Report"
            },
            {
                "v": "Stock_Adjustment_Report",
                "l": "Stock_Adjustment_Report",
                "f": "Stock Adjustment Report"
            },
            {
                "v": "Top_Suppliers_Report",
                "l": "Top_Suppliers_Report",
                "f": "Top Suppliers Report"
            },
            {
                "v": "draft_invoices_report",
                "l": "draft_invoices_report",
                "f": "Draft Invoices Report"
            },
            {
                "v": "discount_summary_report",
                "l": "discount_summary_report",
                "f": "Discount Summary Report"
            },
            {
                "v": "tax_summary_report",
                "l": "tax_summary_report",
                "f": "Tax Summary Report"
            },
            {
                "v": "return_ratio_report",
                "l": "return_ratio_report",
                "f": "Return Ratio Report"
            },
            {
                "v": "negative_stock_report",
                "l": "negative_stock_report",
                "f": "Negative Stock Report"
            },
            {
                "v": "customer_loyalty_points_report",
                "l": "Customer_Loyalty_Points_Report"
            },
            {
                "v": "product_sales_report",
                "l": "product_sales_report"
            },
            {
                "v": "products_sold_summary",
                "l": "products_sold_summary"
            },
            {
                "v": "product_purchases_report",
                "l": "Product_purchases_report"
            },
            {
                "v": "report_error_logs",
                "l": "report_error_logs",
                "f": "Report error logs"
            },
            {
                "v": "report_device_management",
                "l": "Login_Activity_Report"
            },
            {
                "v": "analytics_report",
                "l": "analytics_report",
                "f": "Analytics Report"
            },
            {
                "v": "Stock_Inventory_Valuation",
                "l": "Stock_Inventory_Valuation",
                "f": "Stock Inventory Valuation"
            },
            {
                "v": "serial_numbers_report",
                "l": "serial_numbers_report",
                "f": "Serial Numbers Report"
            }
        ]
    },
    {
        "label": "HRM",
        "items": [
            {
                "v": "view_employee",
                "l": "view_employee"
            },
            {
                "v": "add_employee",
                "l": "Add_Employee"
            },
            {
                "v": "edit_employee",
                "l": "edit_employee"
            },
            {
                "v": "delete_employee",
                "l": "delete_employee"
            },
            {
                "v": "company",
                "l": "Company"
            },
            {
                "v": "department",
                "l": "department"
            },
            {
                "v": "designation",
                "l": "Designation"
            },
            {
                "v": "office_shift",
                "l": "Office_Shift"
            },
            {
                "v": "attendance",
                "l": "Attendance"
            },
            {
                "v": "leave",
                "l": "Leave_request"
            },
            {
                "v": "holiday",
                "l": "Holiday"
            },
            {
                "v": "payroll",
                "l": "payroll",
                "f": "Payroll"
            },
            {
                "v": "contracts",
                "l": "contracts",
                "f": "Contracts"
            },
            {
                "v": "bookings",
                "l": "bookings",
                "f": "Bookings"
            },
            {
                "v": "trays",
                "l": "trays",
                "f": "Trays"
            }
        ]
    },
    {
        "label": "Projects Management",
        "raw": true,
        "items": [
            {
                "v": "projects",
                "l": "projects",
                "f": "Projects"
            },
            {
                "v": "tasks",
                "l": "tasks",
                "f": "Tasks"
            },
            {
                "v": "project_milestones",
                "l": "Milestones",
                "f": "Milestones"
            },
            {
                "v": "project_timesheets",
                "l": "Timesheets",
                "f": "Timesheets"
            },
            {
                "v": "project_reports",
                "l": "Reports",
                "f": "Project reports"
            }
        ]
    },
    {
        "label": "School",
        "raw": true,
        "items": [
            {
                "v": "school_dashboard",
                "l": "dashboard"
            },
            {
                "v": "school_students_view",
                "l": "View"
            },
            {
                "v": "school_students_add",
                "l": "Add"
            },
            {
                "v": "school_students_edit",
                "l": "Edit"
            },
            {
                "v": "school_students_delete",
                "l": "Del"
            },
            {
                "v": "school_teachers",
                "l": "Teachers",
                "f": "Teachers"
            },
            {
                "v": "school_academics",
                "l": "Academics",
                "f": "Academic setup"
            },
            {
                "v": "school_enrollment",
                "l": "Enrolment",
                "f": "Enrolment"
            },
            {
                "v": "school_attendance",
                "l": "Attendance",
                "f": "Attendance"
            },
            {
                "v": "school_exams",
                "l": "Exams",
                "f": "Exams & results"
            },
            {
                "v": "school_timetable",
                "l": "Timetable",
                "f": "Timetable"
            },
            {
                "v": "school_fees",
                "l": "Fees",
                "f": "Fees"
            },
            {
                "v": "school_reports",
                "l": "Reports",
                "f": "Reports"
            }
        ]
    },
    {
        "label": "Hospital (HMS)",
        "raw": true,
        "items": [
            {
                "v": "hms_dashboard",
                "l": "dashboard"
            },
            {
                "v": "hms_patients_view",
                "l": "View"
            },
            {
                "v": "hms_patients_add",
                "l": "Add"
            },
            {
                "v": "hms_patients_edit",
                "l": "Edit"
            },
            {
                "v": "hms_patients_delete",
                "l": "Del"
            },
            {
                "v": "hms_doctors",
                "l": "Doctors",
                "f": "Doctors"
            },
            {
                "v": "hms_departments",
                "l": "Departments",
                "f": "Departments"
            },
            {
                "v": "hms_appointments",
                "l": "Appointments",
                "f": "Appointments"
            },
            {
                "v": "hms_visits",
                "l": "Consultations",
                "f": "Consultations"
            },
            {
                "v": "hms_admissions",
                "l": "Admissions",
                "f": "Admissions"
            },
            {
                "v": "hms_wards",
                "l": "Wards",
                "f": "Wards & Beds"
            },
            {
                "v": "hms_lab",
                "l": "Laboratory",
                "f": "Laboratory"
            },
            {
                "v": "hms_billing",
                "l": "Billing",
                "f": "Billing"
            },
            {
                "v": "hms_reports",
                "l": "Reports",
                "f": "Reports"
            }
        ]
    },
    {
        "label": "Vehicle & Fleet",
        "raw": true,
        "items": [
            {
                "v": "fleet_vehicles_view",
                "l": "View"
            },
            {
                "v": "fleet_vehicles_add",
                "l": "Add"
            },
            {
                "v": "fleet_vehicles_edit",
                "l": "Edit"
            },
            {
                "v": "fleet_vehicles_delete",
                "l": "Del"
            },
            {
                "v": "fleet_maintenance",
                "l": "Maintenance",
                "f": "Maintenance"
            },
            {
                "v": "fleet_fuel",
                "l": "Fuel_Logs",
                "f": "Fuel Logs"
            },
            {
                "v": "fleet_assignments",
                "l": "Assignments",
                "f": "Assignments"
            },
            {
                "v": "fleet_reports",
                "l": "Reports",
                "f": "Reports"
            }
        ]
    },
    {
        "label": "Document Archive",
        "raw": true,
        "items": [
            {
                "v": "documents_view",
                "l": "View"
            },
            {
                "v": "documents_add",
                "l": "Add"
            },
            {
                "v": "documents_edit",
                "l": "Edit"
            },
            {
                "v": "documents_delete",
                "l": "Del"
            }
        ]
    },
    {
        "label": "Recruit",
        "items": [
            {
                "v": "recruit_job",
                "l": "Jobs"
            },
            {
                "v": "recruit_category",
                "l": "Job_Categories"
            },
            {
                "v": "recruit_candidate",
                "l": "Candidates"
            },
            {
                "v": "recruit_application",
                "l": "Applications"
            },
            {
                "v": "recruit_interview",
                "l": "Interviews"
            }
        ]
    },
    {
        "label": "Meeting_Management",
        "items": [
            {
                "v": "meeting",
                "l": "Meetings"
            },
            {
                "v": "meeting_attendance",
                "l": "Manage_Attendance"
            },
            {
                "v": "meeting_report",
                "l": "Reports"
            }
        ]
    },
    {
        "label": "Marketing_Management",
        "items": [
            {
                "v": "marketing_dashboard",
                "l": "Marketing_Dashboard"
            },
            {
                "v": "marketing_campaigns",
                "l": "Campaigns"
            },
            {
                "v": "marketing_segments",
                "l": "Customer_Segments"
            },
            {
                "v": "marketing_templates",
                "l": "Marketing_Templates"
            },
            {
                "v": "marketing_reports",
                "l": "Marketing_Reports"
            },
            {
                "v": "marketing_settings",
                "l": "Marketing_Settings"
            }
        ]
    },
    {
        "label": "Real_Estate",
        "items": [
            {
                "v": "realestate_properties",
                "l": "Properties"
            },
            {
                "v": "realestate_categories",
                "l": "Property_Categories"
            },
            {
                "v": "realestate_inquiries",
                "l": "Property_Inquiries"
            }
        ]
    },
    {
        "label": "Settings",
        "items": [
            {
                "v": "setting_system",
                "l": "SystemSettings"
            },
            {
                "v": "business_modules",
                "l": "Modules"
            },
            {
                "v": "update_settings",
                "l": "update_settings"
            },
            {
                "v": "login_device_management",
                "l": "login_device_management",
                "f": "Login Device Management"
            },
            {
                "v": "loyalty_rewards",
                "l": "Loyalty_Rewards"
            },
            {
                "v": "woocommerce_settings",
                "l": "woocommerce_settings",
                "f": "Woocommerce settings"
            },
            {
                "v": "payment_methods",
                "l": "payment_methods",
                "f": "Payment methods"
            },
            {
                "v": "quickbooks_settings",
                "l": "quickbooks_settings",
                "f": "Quickbooks settings"
            },
            {
                "v": "slack_settings",
                "l": "Slack",
                "f": "Slack integration"
            },
            {
                "v": "telegram_settings",
                "l": "Telegram",
                "f": "Telegram integration"
            },
            {
                "v": "salla_settings",
                "l": "Salla",
                "f": "Salla integration"
            },
            {
                "v": "xero_settings",
                "l": "Xero",
                "f": "Xero integration"
            },
            {
                "v": "prestashop_settings",
                "l": "PrestaShop",
                "f": "PrestaShop integration"
            },
            {
                "v": "google_sheets_settings",
                "l": "Google Sheets",
                "f": "Google Sheets integration"
            },
            {
                "v": "mailchimp_settings",
                "l": "Mailchimp",
                "f": "Mailchimp integration"
            },
            {
                "v": "jumia_settings",
                "l": "Jumia",
                "f": "Jumia integration"
            },
            {
                "v": "zatca_settings",
                "l": "Zatca_E_Invoicing",
                "f": "ZATCA e-invoicing"
            },
            {
                "v": "sms_settings",
                "l": "sms_settings"
            },
            {
                "v": "notification_template",
                "l": "notification_template"
            },
            {
                "v": "pos_settings",
                "l": "pos_settings"
            },
            {
                "v": "payment_gateway",
                "l": "payment_gateway"
            },
            {
                "v": "mail_settings",
                "l": "mail_settings"
            },
            {
                "v": "currency",
                "l": "Currencies"
            },
            {
                "v": "multi_currency",
                "l": "Multi_Currency",
                "f": "Multi currency (change document currency)"
            },
            {
                "v": "warehouse",
                "l": "Warehouses"
            },
            {
                "v": "warehouse_locations",
                "l": "Warehouse_Locations"
            },
            {
                "v": "backup",
                "l": "Backup"
            },
            {
                "v": "system_health_view",
                "l": "System_Health"
            },
            {
                "v": "appearance_settings",
                "l": "appearance_settings",
                "f": "Dynamic Appearance"
            },
            {
                "v": "translations_settings",
                "l": "translations_settings",
                "f": "Translations"
            }
        ]
    },
    {
        "label": "Manufacturing (MRP)",
        "raw": true,
        "items": [
            {
                "v": "mrp_boms",
                "l": "Bills of materials",
                "f": "Bills of materials and work centres"
            },
            {
                "v": "mrp_production",
                "l": "Production orders",
                "f": "Production orders and shop floor"
            },
            {
                "v": "mrp_quality",
                "l": "Quality control",
                "f": "Quality control"
            },
            {
                "v": "mrp_planning",
                "l": "Planning",
                "f": "MRP planning"
            },
            {
                "v": "mrp_reports",
                "l": "Reports",
                "f": "Manufacturing reports"
            }
        ]
    },
    {
        "label": "Shopify",
        "raw": true,
        "items": [
            {
                "v": "shopify_stores",
                "l": "Stores",
                "f": "Shopify stores"
            },
            {
                "v": "shopify_sync",
                "l": "Run syncs",
                "f": "Run syncs"
            },
            {
                "v": "shopify_logs",
                "l": "Logs",
                "f": "Shopify logs"
            }
        ]
    },
    {
        "label": "Online Store",
        "raw": true,
        "items": [
            {
                "v": "Store_settings_view",
                "l": "Store_settings_view",
                "f": "Store Settings"
            },
            {
                "v": "ewallet",
                "l": "ewallet",
                "f": "E-Wallet"
            },
            {
                "v": "Orders_view",
                "l": "Orders_view",
                "f": "Orders"
            },
            {
                "v": "Collections_view",
                "l": "Collections_view",
                "f": "Collections"
            },
            {
                "v": "Banners_view",
                "l": "Banners_view",
                "f": "Banners"
            },
            {
                "v": "Subscribers_view",
                "l": "Subscribers_view",
                "f": "Subscribers"
            },
            {
                "v": "Messages_view",
                "l": "Messages_view",
                "f": "Messages"
            }
        ]
    }
];
