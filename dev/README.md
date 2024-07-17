These tests are not what we would like long term, but for a quick win they have been added as a shell script.

To run the tests
```bash
# Get set up with the module
git clone https://github.com/genecommerce/module-encryption-key-manager
git clone https://github.com/AmpersandHQ/magento-docker-test-instance --branch 0.1.21
cd magento-docker-test-instance

# Install magento
CURRENT_EXTENSION="../module-encryption-key-manager" FULL_INSTALL=1 ./bin/mtest-make 2-4-6-p3

# Setup tests
./bin/mtest 'cp vendor/gene/module-encryption-key-manager/dev/test.sh .'
./bin/mtest 'chmod +x ./test.sh'

# Run tests
./bin/mtest './test.sh'
```
