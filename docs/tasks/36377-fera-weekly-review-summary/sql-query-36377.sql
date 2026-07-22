-- Fera weekly product-review report for Redmine #36377.
--
-- Configure {{period_start}} and {{period_end}} as native Metabase Date
-- parameters. The start is inclusive and the end is exclusive.

WITH
params AS (
    SELECT
        CAST({{period_start}} AS DATETIME) AS current_period_start,
        CAST({{period_end}} AS DATETIME) AS current_period_end
),
periods AS (
    SELECT
        current_period_start,
        current_period_end,
        DATE_SUB(
            current_period_start,
            INTERVAL TIMESTAMPDIFF(SECOND, current_period_start, current_period_end) SECOND
        ) AS previous_period_start,
        current_period_start AS previous_period_end
    FROM params
),
base_reviews AS (
    SELECT
        report.magento_store_id,
        report.magento_store_name,
        COALESCE(
            NULLIF(report.fera_product_id, ''),
            CONCAT('external:', report.external_product_id)
        ) AS product_key,
        report.product_name,
        report.rating,
        report.fera_created_at
    FROM fera_product_review_reporting AS report
    WHERE report.fera_created_at IS NOT NULL
      AND (report.fera_product_id IS NOT NULL OR report.external_product_id IS NOT NULL)
),
store_metrics AS (
    SELECT
        'store' AS row_type,
        base.magento_store_id,
        MAX(base.magento_store_name) AS store,
        'All product reviews' AS product,
        AVG(
            CASE
                WHEN base.fera_created_at >= periods.current_period_start
                 AND base.fera_created_at < periods.current_period_end
                THEN base.rating
            END
        ) AS past_week_rating,
        AVG(
            CASE
                WHEN base.fera_created_at >= periods.previous_period_start
                 AND base.fera_created_at < periods.previous_period_end
                THEN base.rating
            END
        ) AS previous_week_rating,
        SUM(
            CASE
                WHEN base.fera_created_at >= periods.current_period_start
                 AND base.fera_created_at < periods.current_period_end
                 AND base.rating >= 4
                THEN 1 ELSE 0
            END
        ) AS past_week_positive_reviews,
        SUM(
            CASE
                WHEN base.fera_created_at >= periods.current_period_start
                 AND base.fera_created_at < periods.current_period_end
                 AND base.rating <= 3
                THEN 1 ELSE 0
            END
        ) AS past_week_negative_reviews,
        AVG(base.rating) AS all_time_rating,
        COUNT(*) AS all_time_reviews
    FROM base_reviews AS base
    CROSS JOIN periods
    GROUP BY base.magento_store_id
),
product_metrics AS (
    SELECT
        'product' AS row_type,
        base.magento_store_id,
        MAX(base.magento_store_name) AS store,
        base.product_key,
        COALESCE(NULLIF(MAX(base.product_name), ''), base.product_key) AS product,
        AVG(
            CASE
                WHEN base.fera_created_at >= periods.current_period_start
                 AND base.fera_created_at < periods.current_period_end
                THEN base.rating
            END
        ) AS past_week_rating,
        AVG(
            CASE
                WHEN base.fera_created_at >= periods.previous_period_start
                 AND base.fera_created_at < periods.previous_period_end
                THEN base.rating
            END
        ) AS previous_week_rating,
        SUM(
            CASE
                WHEN base.fera_created_at >= periods.current_period_start
                 AND base.fera_created_at < periods.current_period_end
                 AND base.rating >= 4
                THEN 1 ELSE 0
            END
        ) AS past_week_positive_reviews,
        SUM(
            CASE
                WHEN base.fera_created_at >= periods.current_period_start
                 AND base.fera_created_at < periods.current_period_end
                 AND base.rating <= 3
                THEN 1 ELSE 0
            END
        ) AS past_week_negative_reviews,
        AVG(base.rating) AS all_time_rating,
        COUNT(*) AS all_time_reviews
    FROM base_reviews AS base
    CROSS JOIN periods
    GROUP BY base.magento_store_id, base.product_key
),
metrics AS (
    SELECT
        row_type,
        magento_store_id,
        store,
        product,
        past_week_rating,
        previous_week_rating,
        past_week_positive_reviews,
        past_week_negative_reviews,
        all_time_rating,
        all_time_reviews
    FROM store_metrics

    UNION ALL

    SELECT
        row_type,
        magento_store_id,
        store,
        product,
        past_week_rating,
        previous_week_rating,
        past_week_positive_reviews,
        past_week_negative_reviews,
        all_time_rating,
        all_time_reviews
    FROM product_metrics
)
SELECT
    store AS `Store`,
    product AS `Product`,
    ROUND(past_week_rating, 2) AS `Past week rating`,
    ROUND(past_week_rating - previous_week_rating, 2) AS `Rating delta`,
    past_week_positive_reviews AS `Past week positive reviews`,
    past_week_negative_reviews AS `Past week negative reviews`,
    ROUND(all_time_rating, 2) AS `All-time rating`,
    all_time_reviews AS `All-time reviews`
FROM metrics
ORDER BY
    store,
    CASE row_type WHEN 'store' THEN 0 ELSE 1 END,
    product;
