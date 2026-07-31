-- Fera weekly product-review report for Redmine #36377.
--
-- Configure time_period as a required Metabase Field Filter:
-- - map it to fera_review_snapshots.fera_created_at;
-- - set the table and field alias to gd.date_day;
-- - use the All Options widget with Previous 7 days as the default.
--
-- Configure store_filter as an optional Metabase Field Filter:
-- - map it to store.name;
-- - set the table and field alias to stores.name.

WITH RECURSIVE
  generated_dates AS (
    SELECT DATE('2015-08-01') AS date_day
    UNION ALL
    SELECT
      DATE_ADD(date_day, INTERVAL 1 DAY)
    FROM
      generated_dates
    WHERE
      date_day < DATE_ADD(CURRENT_DATE, INTERVAL 10 YEAR)
  ),
  selected_period AS (
    SELECT
      CAST(MIN(gd.date_day) AS DATETIME) AS current_period_start,
      CAST(
        DATE_ADD(MAX(gd.date_day), INTERVAL 1 DAY) AS DATETIME
      ) AS current_period_end
    FROM
      generated_dates AS gd
    WHERE
      {{time_period}}
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
    FROM
      selected_period
  ),
  base_reviews AS (
    SELECT
      snapshots.magento_store_id,
      stores.name AS magento_store_name,
      COALESCE(
        NULLIF(snapshots.fera_product_id, ''),
        CONCAT('external:', snapshots.external_product_id)
      ) AS product_key,
      snapshots.product_name,
      snapshots.rating,
      snapshots.fera_created_at
    FROM
      fera_review_snapshots AS snapshots
      INNER JOIN store AS stores ON stores.store_id = snapshots.magento_store_id
    WHERE
      snapshots.subject = 'product'
      AND snapshots.fera_created_at IS NOT NULL
      AND (
        snapshots.fera_product_id IS NOT NULL
        OR snapshots.external_product_id IS NOT NULL
      ) [[AND {{store_filter}}]]
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
          AND base.fera_created_at < periods.current_period_end THEN base.rating
        END
      ) AS past_week_rating,
      AVG(
        CASE
          WHEN base.fera_created_at >= periods.previous_period_start
          AND base.fera_created_at < periods.previous_period_end THEN base.rating
        END
      ) AS previous_week_rating,
      SUM(
        CASE
          WHEN base.fera_created_at >= periods.current_period_start
          AND base.fera_created_at < periods.current_period_end
          AND base.rating >= 4 THEN 1
          ELSE 0
        END
      ) AS past_week_positive_reviews,
      SUM(
        CASE
          WHEN base.fera_created_at >= periods.current_period_start
          AND base.fera_created_at < periods.current_period_end
          AND base.rating <= 3 THEN 1
          ELSE 0
        END
      ) AS past_week_negative_reviews,
      AVG(base.rating) AS all_time_rating,
      COUNT(*) AS all_time_reviews
    FROM
      base_reviews AS base
      CROSS JOIN periods
    GROUP BY
      base.magento_store_id
  ),
  product_metrics AS (
    SELECT
      'product' AS row_type,
      base.magento_store_id,
      MAX(base.magento_store_name) AS store,
      base.product_key,
      COALESCE(
        NULLIF(MAX(base.product_name), ''),
        base.product_key
      ) AS product,
      AVG(
        CASE
          WHEN base.fera_created_at >= periods.current_period_start
          AND base.fera_created_at < periods.current_period_end THEN base.rating
        END
      ) AS past_week_rating,
      AVG(
        CASE
          WHEN base.fera_created_at >= periods.previous_period_start
          AND base.fera_created_at < periods.previous_period_end THEN base.rating
        END
      ) AS previous_week_rating,
      SUM(
        CASE
          WHEN base.fera_created_at >= periods.current_period_start
          AND base.fera_created_at < periods.current_period_end
          AND base.rating >= 4 THEN 1
          ELSE 0
        END
      ) AS past_week_positive_reviews,
      SUM(
        CASE
          WHEN base.fera_created_at >= periods.current_period_start
          AND base.fera_created_at < periods.current_period_end
          AND base.rating <= 3 THEN 1
          ELSE 0
        END
      ) AS past_week_negative_reviews,
      AVG(base.rating) AS all_time_rating,
      COUNT(*) AS all_time_reviews
    FROM
      base_reviews AS base
      CROSS JOIN periods
    GROUP BY
      base.magento_store_id,
      base.product_key
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
    FROM
      store_metrics
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
    FROM
      product_metrics
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
FROM
  metrics
WHERE
  past_week_positive_reviews > 0
  OR past_week_negative_reviews > 0
ORDER BY
  magento_store_id,
  CASE row_type
    WHEN 'store' THEN 0
    ELSE 1
  END,
  product;