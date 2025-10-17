<?php
/**
 * Connections View
 *
 * Variables:
 * - $oai_key_masked
 * - $pine_key_masked
 * - $model
 * - $dimension
 * - $allowed_models
 * - $oai_vs_id
 * - $pine_env
 * - $pine_project
 * - $pine_index
 * - $sodium_available
 *
 * @package WCVec
 */

defined('ABSPATH') || exit;

$action_url = add_query_arg(['page' => 'wcvec', 'tab' => 'connections'], admin_url('admin.php'));

// Labels for models with dimensions.
$model_labels = [
  \WCVec\Options::MODEL_3_LARGE => sprintf('%s (%s)', \WCVec\Options::MODEL_3_LARGE, '1536'),
  \WCVec\Options::MODEL_3_SMALL => sprintf('%s (%s)', \WCVec\Options::MODEL_3_SMALL, '3072'),
  \WCVec\Options::MODEL_ADA002  => sprintf('%s (%s)', \WCVec\Options::MODEL_ADA002,  '1536'),
];

?>
<div class="wcvec-connections">
  <form method="post" action="<?php echo esc_url($action_url); ?>">
    <?php wp_nonce_field('wcvec_connections_save', 'wcvec_nonce'); ?>
    <input type="hidden" name="wcvec_action" value="save_connections" />

    <div class="card">
      <h2><?php esc_html_e('OpenAI', 'wc-vector-indexing'); ?></h2>

      <table class="form-table" role="presentation">
        <tr>
          <th scope="row">
            <label for="wcvec_oai_key"><?php esc_html_e('API Key', 'wc-vector-indexing'); ?></label>
          </th>
          <td>
            <div class="wcvec-secret">
              <div class="wcvec-secret-view" data-target="#wcvec_oai_key">
                <input type="text" class="regular-text" value="<?php echo esc_attr($oai_key_masked); ?>" readonly />
                <button type="button" class="button wcvec-secret-toggle" data-target="#wcvec_oai_key">
                  <?php esc_html_e('Change', 'wc-vector-indexing'); ?>
                </button>
              </div>
              <input type="password" name="wcvec_oai_key" id="wcvec_oai_key" class="regular-text wcvec-secret-input" style="display:none;" autocomplete="off" placeholder="<?php esc_attr_e('Enter new key (leave blank to keep current)', 'wc-vector-indexing'); ?>" />
            </div>
            <p class="description">
              <?php esc_html_e('Used for embeddings and OpenAI Vector Store operations.', 'wc-vector-indexing'); ?>
            </p>
            <?php if (!$sodium_available): ?>
              <p class="notice-inline notice-warning"><em><?php esc_html_e('Warning: Sodium not available; API keys may not be encrypted at rest.', 'wc-vector-indexing'); ?></em></p>
            <?php endif; ?>
          </td>
        </tr>

        <tr>
          <th scope="row">
            <label for="wcvec_model"><?php esc_html_e('Embedding Model', 'wc-vector-indexing'); ?></label>
          </th>
          <td>
            <select name="wcvec_model" id="wcvec_model">
              <?php foreach ($allowed_models as $m): ?>
                <option value="<?php echo esc_attr($m); ?>" <?php selected($model, $m); ?>>
                  <?php echo esc_html($model_labels[$m] ?? $m); ?>
                </option>
              <?php endforeach; ?>
            </select>
            <p class="description">
              <?php esc_html_e('Model selection controls the embedding dimension automatically.', 'wc-vector-indexing'); ?>
            </p>
          </td>
        </tr>

        <tr>
          <th scope="row">
            <label for="wcvec_dimension"><?php esc_html_e('Embedding Dimension', 'wc-vector-indexing'); ?></label>
          </th>
          <td>
            <input type="number" id="wcvec_dimension" class="small-text" value="<?php echo esc_attr((string) $dimension); ?>" readonly />
            <p class="description"><?php esc_html_e('Auto-set by model. Editable later under Advanced if needed.', 'wc-vector-indexing'); ?></p>
          </td>
        </tr>

        <tr>
          <th scope="row">
            <label for="wcvec_oai_vectorstore_id"><?php esc_html_e('OpenAI Vector Store ID (optional)', 'wc-vector-indexing'); ?></label>
          </th>
          <td>
            <input type="text" name="wcvec_oai_vectorstore_id" id="wcvec_oai_vectorstore_id" class="regular-text" value="<?php echo esc_attr($oai_vs_id); ?>" />
            <p class="description">
              <?php esc_html_e('Leave empty to create/select later. Used when pushing vectors to OpenAI Vector Store.', 'wc-vector-indexing'); ?>
            </p>
          </td>
        </tr>
      </table>
      <p>
        <button type="button" class="button" id="wcvec-validate-openai"><?php esc_html_e('Validate OpenAI', 'wc-vector-indexing'); ?></button>
      </p>
      <div id="wcvec-validate-openai-result" class="wcvec-validate-result" aria-live="polite"></div>
    </div>

    <div class="card">
      <h2><?php esc_html_e('Pinecone', 'wc-vector-indexing'); ?></h2>

      <table class="form-table" role="presentation">
        <tr>
          <th scope="row">
            <label for="wcvec_pine_key"><?php esc_html_e('API Key', 'wc-vector-indexing'); ?></label>
          </th>
          <td>
            <div class="wcvec-secret">
              <div class="wcvec-secret-view" data-target="#wcvec_pine_key">
                <input type="text" class="regular-text" value="<?php echo esc_attr($pine_key_masked); ?>" readonly />
                <button type="button" class="button wcvec-secret-toggle" data-target="#wcvec_pine_key">
                  <?php esc_html_e('Change', 'wc-vector-indexing'); ?>
                </button>
              </div>
              <input type="password" name="wcvec_pine_key" id="wcvec_pine_key" class="regular-text wcvec-secret-input" style="display:none;" autocomplete="off" placeholder="<?php esc_attr_e('Enter new key (leave blank to keep current)', 'wc-vector-indexing'); ?>" />
            </div>
          </td>
        </tr>

        <tr>
          <th scope="row">
            <label for="wcvec_pine_env"><?php esc_html_e('Environment', 'wc-vector-indexing'); ?></label>
          </th>
          <td>
            <input type="text" name="wcvec_pine_env" id="wcvec_pine_env" class="regular-text" value="<?php echo esc_attr($pine_env); ?>" placeholder="e.g. us-east-1-aws" />
          </td>
        </tr>

        <tr>
          <th scope="row">
            <label for="wcvec_pine_project"><?php esc_html_e('Project', 'wc-vector-indexing'); ?></label>
          </th>
          <td>
            <input type="text" name="wcvec_pine_project" id="wcvec_pine_project" class="regular-text" value="<?php echo esc_attr($pine_project); ?>" />
          </td>
        </tr>

        <tr>
          <th scope="row">
            <label for="wcvec_pine_index"><?php esc_html_e('Index name', 'wc-vector-indexing'); ?></label>
          </th>
          <td>
            <input type="text" name="wcvec_pine_index" id="wcvec_pine_index" class="regular-text" value="<?php echo esc_attr($pine_index); ?>" />
            <p class="description">
              <?php esc_html_e('The Pinecone index dimension must match the embedding dimension.', 'wc-vector-indexing'); ?>
            </p>
          </td>
        </tr>
      </table>
      <p>
        <button type="button" class="button" id="wcvec-validate-pinecone"><?php esc_html_e('Validate Pinecone', 'wc-vector-indexing'); ?></button>
      </p>
      <div id="wcvec-validate-pinecone-result" class="wcvec-validate-result" aria-live="polite"></div>
    </div>

    <p>
      <button type="submit" class="button button-primary"><?php esc_html_e('Save Settings', 'wc-vector-indexing'); ?></button>
    </p>

    <p class="description">
      <?php esc_html_e('Note: Selected product fields and ACF mapping are configured under the Fields tab in a later phase.', 'wc-vector-indexing'); ?>
    </p>
  </form>

  <hr/>

  <div class="card">
    <h2><?php esc_html_e('Sample embed & upsert (smoke test)', 'wc-vector-indexing'); ?></h2>
    <p class="description">
      <?php esc_html_e('Pick a product and push its chunks to your configured vector stores using the current field selection and chunking settings. No front-end usage yet — this is a connection and pipeline sanity check.', 'wc-vector-indexing'); ?>
    </p>

    <p>
      <label for="wcvec-sample-product-id"><strong><?php esc_html_e('Product ID', 'wc-vector-indexing'); ?></strong></label>
      <input type="number" min="1" id="wcvec-sample-product-id" class="small-text" placeholder="e.g., 123" />
      <button type="button" class="button" id="wcvec-sample-use-first"><?php esc_html_e('Use first published product', 'wc-vector-indexing'); ?></button>
    </p>

    <p>
      <button type="button" class="button button-primary wcvec-sample-upsert" data-target="pinecone">
        <?php esc_html_e('Upsert → Pinecone', 'wc-vector-indexing'); ?>
      </button>
      <button type="button" class="button button-primary wcvec-sample-upsert" data-target="openai">
        <?php esc_html_e('Upsert → OpenAI Vector Store', 'wc-vector-indexing'); ?>
      </button>
      &nbsp;&nbsp;
      <button type="button" class="button wcvec-sample-delete" data-target="pinecone">
        <?php esc_html_e('Delete product from Pinecone', 'wc-vector-indexing'); ?>
      </button>
      <button type="button" class="button wcvec-sample-delete" data-target="openai">
        <?php esc_html_e('Delete product from OpenAI Vector Store', 'wc-vector-indexing'); ?>
      </button>
    </p>

    <div id="wcvec-sample-status" class="wcvec-sample-status" aria-live="polite"></div>
    <pre id="wcvec-sample-details" class="wcvec-sample-details" style="display:none;"></pre>
  </div>

</div>
